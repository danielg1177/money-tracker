<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayDebtRequest;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClosedMonthGuard;
use App\Services\DebtService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DebtController extends Controller
{
    public function __construct(
        private DebtService $debtService,
        private ClosedMonthGuard $closedMonthGuard,
    ) {}

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
            $this->closedMonthGuard->assertDebtPaymentOpen(
                $user,
                $request->input('transaction_date'),
            );

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
     * Return payment history for a debt. Personal in-family running debts include overpayment
     * reversal lineage (not every independent loan between the same pair).
     */
    public function paymentHistory(Debt $debt): JsonResponse
    {
        $user = auth()->user();
        if ($debt->debtor_id !== $user->id && $debt->creditor_id !== $user->id && ! $user->can_manage_family) {
            abort(403);
        }

        $relatedDebts = $this->relatedHistoryDebts($debt);

        $timeline = $relatedDebts
            ->flatMap(fn (Debt $relatedDebt) => $this->buildDebtHistoryEntries($relatedDebt, $user))
            ->sortByDesc(function (array $entry) {
                return sprintf('%s|%s', (string) $entry['transaction_date'], (string) $entry['created_at']);
            })
            ->values();

        $contributions = $relatedDebts
            ->flatMap(function (Debt $relatedDebt) {
                return collect($relatedDebt->contributions ?? [])
                    ->filter(fn (array $contribution): bool => isset($contribution['year'], $contribution['month']))
                    ->map(fn (array $contribution): array => array_merge($contribution, [
                        'debt_id' => $relatedDebt->id,
                    ]));
            })
            ->sortBy([
                ['year', 'asc'],
                ['month', 'asc'],
            ])
            ->values();

        $openDebt = $relatedDebts->first(
            fn (Debt $relatedDebt): bool => round((float) $relatedDebt->balance, 2) >= 0.01
        );
        $remainingDebt = $openDebt ?? $debt;
        $remainingDebt->loadMissing(['debtor', 'creditor']);

        return response()->json([
            'entries' => $timeline,
            'contributions' => $contributions,
            'remaining' => round((float) ($openDebt?->balance ?? 0), 2),
            'remaining_debtor_id' => $remainingDebt->debtor_id,
            'remaining_creditor_id' => $remainingDebt->creditor_id,
            'remaining_debtor_name' => $remainingDebt->debtor?->name ?? 'Debtor',
            'remaining_creditor_name' => $remainingDebt->creditor?->name ?? ($remainingDebt->creditor_name ?: 'Creditor'),
        ]);
    }

    /**
     * Lineage for history: this debt plus overpayment-created reverse debts linked via
     * reversed_from_debt_id (walked both directions). Independent loans between the same
     * pair are not included.
     *
     * @return Collection<int, Debt>
     */
    private function relatedHistoryDebts(Debt $debt): Collection
    {
        $debt->loadMissing(['debtor', 'creditor']);

        if (! $this->isPersonalInterFamilyRunningDebt($debt)) {
            return collect([$debt]);
        }

        $ids = [(int) $debt->id];

        $current = $debt;
        while ($current->reversed_from_debt_id) {
            $parent = Debt::query()
                ->where('family_id', $debt->family_id)
                ->whereKey($current->reversed_from_debt_id)
                ->first();
            if ($parent === null || in_array((int) $parent->id, $ids, true)) {
                break;
            }
            $ids[] = (int) $parent->id;
            $current = $parent;
        }

        $frontier = $ids;
        while ($frontier !== []) {
            $children = Debt::query()
                ->where('family_id', $debt->family_id)
                ->whereIn('reversed_from_debt_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            if ($children === []) {
                break;
            }
            $ids = array_values(array_merge($ids, $children));
            $frontier = $children;
        }

        return Debt::query()
            ->whereIn('id', $ids)
            ->with(['debtor', 'creditor'])
            ->orderBy('id')
            ->get();
    }

    private function isPersonalInterFamilyRunningDebt(Debt $debt): bool
    {
        return $debt->creditor_id !== null
            && ! $debt->is_family_debt
            && $debt->fund_id === null
            && $debt->transaction_id === null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildDebtHistoryEntries(Debt $debt, User $user): Collection
    {
        $debt->loadMissing(['debtor', 'creditor']);

        $debtorName = $debt->debtor?->name ?? 'Debtor';
        $creditorName = $debt->creditor?->name ?? ($debt->creditor_name ?: 'Creditor');
        $isDirectionReversal = is_string($debt->description)
            && str_starts_with($debt->description, 'Reversed from overpayment:');

        $flowMeta = [
            'debt_id' => $debt->id,
            'debtor_id' => $debt->debtor_id,
            'creditor_id' => $debt->creditor_id,
            'debtor_name' => $debtorName,
            'creditor_name' => $creditorName,
            'is_direction_reversal' => $isDirectionReversal,
        ];

        $paymentsQuery = Transaction::query()
            ->where('debt_id', $debt->id)
            ->where(function ($query) {
                $query->where('is_loan_receipt', false)
                    ->orWhereNull('is_loan_receipt');
            })
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
            ->map(function (Transaction $payment) use ($flowMeta, $debt) {
                return array_merge($flowMeta, [
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
                    'flow_kind' => 'payment',
                    'flow_from_user_id' => $payment->paid_by_user_id ?? $debt->debtor_id,
                    'flow_from_user_name' => $payment->paidByUser?->name ?? $flowMeta['debtor_name'],
                    'flow_to_user_id' => $debt->creditor_id,
                    'flow_to_user_name' => $flowMeta['creditor_name'],
                ]);
            });

        $interestAccrualEntries = collect($debt->interest_accruals ?? [])
            ->map(function (array $accrual) use ($flowMeta) {
                return array_merge($flowMeta, [
                    'id' => null,
                    'amount' => (float) ($accrual['amount'] ?? 0),
                    'description' => 'Monthly Interest Accrued',
                    'transaction_date' => $accrual['applied_at'] ?? null,
                    'type' => 'interest_accrual',
                    'created_at' => $accrual['applied_at'] ?? null,
                    'paid_by_user_id' => null,
                    'is_closeout_initiated' => true,
                    'paid_by_user' => null,
                    'flow_kind' => 'interest',
                    'flow_from_user_id' => null,
                    'flow_from_user_name' => null,
                    'flow_to_user_id' => null,
                    'flow_to_user_name' => null,
                ]);
            })
            ->filter(fn (array $entry): bool => ! empty($entry['transaction_date']));

        $incomeAdditionEntries = collect($debt->income_additions ?? [])
            ->filter(fn (array $entry): bool => isset($entry['date']) && isset($entry['amount']))
            ->map(function (array $entry) use ($flowMeta, $debt): array {
                return array_merge($flowMeta, [
                    'id' => $entry['transaction_id'] ?? null,
                    'amount' => (float) $entry['amount'],
                    'description' => 'Loan Addition',
                    'transaction_date' => $entry['date'],
                    'type' => 'income_addition',
                    'created_at' => $entry['date'],
                    'paid_by_user_id' => null,
                    'is_closeout_initiated' => false,
                    'paid_by_user' => null,
                    'flow_kind' => 'loan',
                    'flow_from_user_id' => $debt->creditor_id,
                    'flow_from_user_name' => $flowMeta['creditor_name'],
                    'flow_to_user_id' => $debt->debtor_id,
                    'flow_to_user_name' => $flowMeta['debtor_name'],
                ]);
            });

        $receiptEntries = Transaction::query()
            ->where('debt_id', $debt->id)
            ->where('type', 'income')
            ->where('is_loan_receipt', true)
            ->orderByDesc('transaction_date')
            ->get()
            ->map(function (Transaction $tx) use ($flowMeta, $debt): array {
                return array_merge($flowMeta, [
                    'id' => $tx->id,
                    'amount' => (float) $tx->amount,
                    'description' => $tx->description ?: 'Loan Received',
                    'transaction_date' => $tx->transaction_date instanceof Carbon
                        ? $tx->transaction_date->toDateString()
                        : (string) $tx->transaction_date,
                    'type' => 'loan_receipt',
                    'created_at' => $tx->created_at,
                    'paid_by_user_id' => null,
                    'is_closeout_initiated' => false,
                    'paid_by_user' => null,
                    'flow_kind' => 'loan',
                    'flow_from_user_id' => $debt->creditor_id,
                    'flow_from_user_name' => $flowMeta['creditor_name'],
                    'flow_to_user_id' => $debt->debtor_id,
                    'flow_to_user_name' => $flowMeta['debtor_name'],
                ]);
            });

        $closeoutContributionsTotal = collect($debt->contributions ?? [])
            ->sum(static fn (array $contribution): float => (float) ($contribution['amount'] ?? 0.0));
        $incomingReversalTotal = collect($debt->direction_reversals ?? [])
            ->filter(fn (array $reversal): bool => isset($reversal['source_debt_id']))
            ->sum(static fn (array $reversal): float => (float) ($reversal['amount'] ?? 0.0));
        $initialPrincipalAmount = max(0.0, round((float) $debt->amount - (float) $closeoutContributionsTotal - (float) $incomingReversalTotal, 2));

        $initialValueEntries = collect();
        if ($initialPrincipalAmount >= 0.01) {
            $initialValueEntries->push(array_merge($flowMeta, [
                'id' => null,
                'amount' => $initialPrincipalAmount,
                'description' => $isDirectionReversal ? 'Direction reversed' : 'Loan started',
                'transaction_date' => $debt->created_at->toDateString(),
                'type' => 'initial_value',
                'created_at' => $debt->created_at,
                'paid_by_user_id' => null,
                'is_closeout_initiated' => false,
                'paid_by_user' => null,
                'flow_kind' => 'loan',
                'flow_from_user_id' => $debt->creditor_id,
                'flow_from_user_name' => $creditorName,
                'flow_to_user_id' => $debt->debtor_id,
                'flow_to_user_name' => $debtorName,
            ]));
        }

        $directionReversalEntries = collect($debt->direction_reversals ?? [])
            ->filter(fn (array $reversal): bool => isset($reversal['amount'], $reversal['applied_at']))
            ->map(function (array $reversal) use ($flowMeta, $debt, $debtorName, $creditorName): array {
                $isOutgoing = isset($reversal['target_debt_id']);

                return array_merge($flowMeta, [
                    'id' => null,
                    'amount' => (float) $reversal['amount'],
                    'description' => 'Direction reversed',
                    'transaction_date' => $reversal['applied_at'],
                    'type' => 'initial_value',
                    'created_at' => $reversal['applied_at'],
                    'paid_by_user_id' => null,
                    'is_closeout_initiated' => false,
                    'paid_by_user' => null,
                    'is_direction_reversal' => true,
                    'flow_kind' => 'loan',
                    'flow_from_user_id' => $isOutgoing ? $debt->debtor_id : $debt->creditor_id,
                    'flow_from_user_name' => $isOutgoing ? $debtorName : $creditorName,
                    'flow_to_user_id' => $isOutgoing ? $debt->creditor_id : $debt->debtor_id,
                    'flow_to_user_name' => $isOutgoing ? $creditorName : $debtorName,
                ]);
            });

        return $payments
            ->concat($interestAccrualEntries)
            ->concat($incomeAdditionEntries)
            ->concat($receiptEntries)
            ->concat($directionReversalEntries)
            ->concat($initialValueEntries);
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
