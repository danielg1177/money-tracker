<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayDebtRequest;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebtController extends Controller
{
    public function __construct(private DebtService $debtService) {}

    /**
     * Get all debts for the authenticated user, organized into personal, owing, and family debts.
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json([]);
        }

        $personalOwed = Debt::query()
            ->where('debtor_id', $user->id)
            ->where('family_id', $user->family_id)
            ->where('is_pending_closeout', false)
            ->where('is_family_debt', false)
            ->with('creditor', 'debtor')
            ->get();

        $personalOwing = Debt::query()
            ->where('creditor_id', $user->id)
            ->where('family_id', $user->family_id)
            ->where('is_pending_closeout', false)
            ->where('is_family_debt', false)
            ->with('creditor', 'debtor')
            ->get();

        $familyDebts = Debt::query()
            ->where('family_id', $user->family_id)
            ->where('is_family_debt', true)
            ->where('is_pending_closeout', false)
            ->with('debtor', 'creditor')
            ->get();

        return response()->json([
            'owed' => $personalOwed,
            'owing' => $personalOwing,
            'family_debts' => $familyDebts,
        ]);
    }

    /**
     * Create a new debt record.
     *
     * Supports three types:
     * - Personal debts to external parties (creditor_name provided, creditor_id null)
     * - In-family debts between users (is_interfamily=true, creditor_id provided)
     * - Family-shared debts visible to the whole family (is_family_debt=true, viewed by all)
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        $validated = $request->validate([
            'is_family_debt' => 'boolean',
            'is_interfamily' => 'boolean',
            'creditor_id' => 'nullable|integer|exists:users,id',
            'creditor_name' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string',
            'interest_enabled' => 'nullable|boolean',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'loan_received_date' => 'nullable|date',
        ]);

        if ($request->boolean('is_interfamily')) {
            if (! $request->creditor_id) {
                return response()->json(['message' => 'creditor_id is required for in-family debts'], 422);
            }
            $creditor = User::findOrFail($request->creditor_id);
            if ($creditor->family_id !== $user->family_id || $creditor->id === $user->id) {
                return response()->json(['message' => 'Creditor must be a different family member'], 422);
            }
        } else {
            if (! $request->creditor_name) {
                return response()->json(['message' => 'creditor_name is required for external debts'], 422);
            }
        }

        $debt = Debt::create([
            'family_id' => $user->family_id,
            'debtor_id' => $user->id,
            'creditor_id' => $request->boolean('is_interfamily') ? $request->creditor_id : null,
            'creditor_name' => ! $request->boolean('is_interfamily') ? $request->creditor_name : null,
            'amount' => $request->amount,
            'balance' => $request->amount,
            'description' => $request->description,
            'is_family_debt' => $request->boolean('is_family_debt'),
            'is_pending_closeout' => false,
            'interest_enabled' => $request->boolean('interest_enabled'),
            'interest_rate' => $request->boolean('interest_enabled') ? $request->input('interest_rate', 0) : null,
            'interest_last_applied_at' => null,
            'loan_received_date' => $request->input('loan_received_date'),
        ]);

        return response()->json($debt->load('debtor', 'creditor'));
    }

    /**
     * Update a debt's description and creditor name.
     *
     * Only the debtor or a family manager may update.
     */
    public function update(Request $request, Debt $debt): JsonResponse
    {
        $user = auth()->user();
        if ($debt->debtor_id !== $user->id && ! $user->can_manage_family) {
            abort(403);
        }
        if ($debt->is_pending_closeout) {
            return response()->json(['message' => 'Cannot edit a pending split debt'], 422);
        }
        $validated = $request->validate([
            'description' => 'nullable|string|max:1000',
            'creditor_name' => 'nullable|string|max:255',
            'interest_enabled' => 'nullable|boolean',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'loan_received_date' => 'nullable|date',
        ]);

        if (array_key_exists('interest_enabled', $validated)) {
            if (! $validated['interest_enabled']) {
                $validated['interest_rate'] = null;
            } elseif (! array_key_exists('interest_rate', $validated)) {
                $validated['interest_rate'] = $debt->interest_rate ?? 0;
            }
        }

        $debt->update($validated);

        return response()->json($debt->load('debtor', 'creditor'));
    }

    /**
     * Record a debt payment.
     */
    public function payDebt(PayDebtRequest $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        try {
            $debt = Debt::query()->findOrFail($request->debt_id);

            $this->debtService->payDebt(
                $debt,
                $request->amount,
                $request->description ?? '',
                $user,
                false,
                $request->input('transaction_date'),
                $request->split_with_user_id ? (int) $request->split_with_user_id : null,
                $request->split_percentage ? (float) $request->split_percentage : null,
            );

            return response()->json(['message' => 'Debt payment recorded']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Return payment transactions linked to a specific debt (one row per pay action for inter-member debts).
     */
    public function paymentHistory(Debt $debt): JsonResponse
    {
        $user = auth()->user();
        if ($debt->debtor_id !== $user->id && $debt->creditor_id !== $user->id && ! $user->can_manage_family) {
            abort(403);
        }

        $paymentsQuery = Transaction::query()
            ->where('debt_id', $debt->id)
            ->with(['paidByUser', 'splits.user', 'mirrorTransaction.splits.user']);

        $isViewerCreditor = $debt->creditor_id !== null && $debt->creditor_id === $user->id;
        if ($isViewerCreditor) {
            $paymentsQuery->where('type', 'income')
                ->where('user_id', $user->id);
        } else {
            $paymentsQuery->where('type', 'expense');
        }

        $payments = $paymentsQuery
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'description' => $payment->description,
                    'transaction_date' => $payment->transaction_date,
                    'type' => $payment->type,
                    'created_at' => $payment->created_at,
                    'paid_by_user_id' => $payment->paid_by_user_id,
                    'is_closeout_initiated' => $payment->is_closeout_initiated,
                    'split_breakdown' => $this->resolveSplitBreakdown($payment),
                    'paid_by_user' => $payment->paidByUser ? [
                        'id' => $payment->paidByUser->id,
                        'name' => $payment->paidByUser->name,
                    ] : null,
                ];
            });

        $interestAccrualEntries = collect($debt->interest_accruals ?? [])
            ->map(function (array $accrual) {
                return [
                    'id' => null,
                    'amount' => (float) ($accrual['amount'] ?? 0),
                    'description' => 'Monthly Interest Accrued',
                    'transaction_date' => $accrual['applied_at'] ?? null,
                    'type' => 'interest_accrual',
                    'created_at' => $accrual['applied_at'] ?? null,
                    'paid_by_user_id' => null,
                    'is_closeout_initiated' => true,
                    'paid_by_user' => null,
                ];
            })
            ->filter(fn (array $entry): bool => ! empty($entry['transaction_date']));

        $initialValueEntry = [
            'id' => null,
            'amount' => $debt->amount,
            'description' => 'Initial Value Set At',
            'transaction_date' => $debt->created_at->toDateString(),
            'type' => 'initial_value',
            'created_at' => $debt->created_at,
            'paid_by_user_id' => null,
            'is_closeout_initiated' => false,
            'paid_by_user' => null,
        ];

        $timeline = $payments
            ->concat($interestAccrualEntries)
            ->sortByDesc(function (array $entry) {
                return sprintf('%s|%s', (string) $entry['transaction_date'], (string) $entry['created_at']);
            })
            ->values()
            ->push($initialValueEntry);

        return response()->json($timeline->values());
    }

    /**
     * @return array<int, array{user_id:int|null,user_name:string,amount:float,share_percentage:float}>|null
     */
    private function resolveSplitBreakdown(Transaction $payment): ?array
    {
        $splitSource = null;

        if ($payment->type === 'expense') {
            $splitSource = $payment;
        }

        if ($payment->type === 'income') {
            $mirror = $payment->mirrorTransaction;
            if ($mirror && $mirror->type === 'expense') {
                $splitSource = $mirror;
            } else {
                $splitSource = Transaction::query()
                    ->where('debt_id', $payment->debt_id)
                    ->where('type', 'expense')
                    ->where('is_debt_payment', true)
                    ->whereDate('transaction_date', $payment->transaction_date)
                    ->where('amount', $payment->amount)
                    ->where('paid_by_user_id', $payment->paid_by_user_id)
                    ->where('family_id', $payment->family_id)
                    ->with('splits.user')
                    ->orderByDesc('created_at')
                    ->first();
            }
        }

        if (! $splitSource || ! $splitSource->is_split) {
            return null;
        }

        $splitSource->loadMissing('splits.user');

        return $splitSource->splits
            ->map(fn ($split): array => [
                'user_id' => $split->user?->id,
                'user_name' => $split->user?->name ?? 'Unknown user',
                'amount' => (float) $split->amount,
                'share_percentage' => (float) $split->share_percentage,
            ])
            ->values()
            ->all();
    }

    /**
     * Delete a debt record.
     *
     * Only the debtor or a family manager can delete. Cannot delete pending closeout debts.
     */
    public function destroy(Debt $debt): JsonResponse
    {
        $user = auth()->user();

        if ($debt->debtor_id !== $user->id && ! $user->can_manage_family) {
            abort(403);
        }

        if ($debt->is_pending_closeout) {
            return response()->json(['message' => 'Cannot delete a pending split debt'], 422);
        }

        $debt->delete();

        return response()->json(['message' => 'Debt deleted']);
    }

    /**
     * Get a summary of pending split debts for the current user's family, grouped by counterpart.
     */
    public function splitDebtSummary(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json([]);
        }

        $year = (int) $request->year;
        $month = (int) $request->month;

        $pendingDebts = Debt::query()
            ->where('family_id', $user->family_id)
            ->where('is_pending_closeout', true)
            ->with([
                'debtor',
                'creditor',
                'transaction.category',
                'transaction.debt.creditor',
                'transaction.debt.debtor',
                'transaction.debt.fund',
            ])
            ->whereHas('transaction', fn ($q) => $q->whereYear('transaction_date', $year)->whereMonth('transaction_date', $month))
            ->get();

        $myDebts = $pendingDebts->filter(fn ($d) => $d->debtor_id === $user->id || $d->creditor_id === $user->id);

        $summary = [];
        foreach ($myDebts as $debt) {
            $isDebtor = $debt->debtor_id === $user->id;
            $counterpartId = $isDebtor ? $debt->creditor_id : $debt->debtor_id;
            $counterpart = $isDebtor ? $debt->creditor : $debt->debtor;

            if (! isset($summary[$counterpartId])) {
                $summary[$counterpartId] = [
                    'counterpart' => $counterpart,
                    'you_owe' => 0,
                    'they_owe' => 0,
                    'transactions' => [],
                ];
            }

            if ($isDebtor) {
                $summary[$counterpartId]['you_owe'] += (float) $debt->amount;
            } else {
                $summary[$counterpartId]['they_owe'] += (float) $debt->amount;
            }

            $summary[$counterpartId]['transactions'][] = [
                'debt_id' => $debt->id,
                'transaction' => $debt->transaction,
                'amount' => (float) $debt->amount,
                'direction' => $isDebtor ? 'you_owe' : 'they_owe',
            ];
        }

        return response()->json(array_values($summary));
    }
}
