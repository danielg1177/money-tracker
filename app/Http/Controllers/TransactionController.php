<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Models\Transaction;
use App\Services\ClosedMonthGuard;
use App\Services\TransactionRepaymentService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private TransactionService $transactionService,
        private ClosedMonthGuard $closedMonthGuard,
        private TransactionRepaymentService $repaymentService,
    ) {}

    /**
     * List transactions relevant to the authenticated user: rows they created, or family
     * split transactions where they appear in `transaction_splits` (including as payer).
     *
     * Split debt payments create a payer expense (with optional splits) plus creditor income;
     * when the creditor is also a split participant on that expense, the income row is kept
     * and the mirrored expense leg is omitted so the payment appears once in their list.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json([]);
        }

        $query = $user->family->transactions()
            ->with([
                'user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund', 'advanceFund', 'plaidPendingImport.plaidItem',
                'repaymentLinks.repaidTransaction.category',
                'repaymentLinks.mirrorTransaction.user',
                'repaymentLinks.repaidUser',
                'repaidByLink.repaymentTransaction.user',
                'mirrorRepaymentLink.repaymentTransaction.user',
            ])
            ->where(function ($q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhereHas('splits', function ($splitQuery) use ($user): void {
                        $splitQuery->where('user_id', $user->id);
                    });
            })
            ->whereNot(function ($q) use ($user): void {
                $q->where('is_debt_payment', true)
                    ->where('type', 'expense')
                    ->where('user_id', '!=', $user->id)
                    ->whereHas('splits', function ($splitQuery) use ($user): void {
                        $splitQuery->where('user_id', $user->id);
                    })
                    ->whereHas('debt', function ($debtQuery) use ($user): void {
                        $debtQuery->where('creditor_id', $user->id);
                    });
            });

        if ($request->filled('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->input('end_date'));
        }

        return response()->json($query->get());
    }

    /**
     * Returns the authenticated user's expense transactions that have not yet been repaid.
     * Used to populate the repayment expense selector in the transaction form.
     */
    public function repayableExpenses(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json([]);
        }

        $query = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_repaid', false)
            ->where('is_repayment_mirror', false)
            ->where('is_closeout_initiated', false)
            ->with(['category'])
            ->orderBy('transaction_date', 'desc');

        if ($request->filled('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->input('end_date'));
        }

        return response()->json($query->get());
    }

    /**
     * Create a new transaction.
     *
     * @return JsonResponse
     */
    public function store(StoreTransactionRequest $request)
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        try {
            $validated = $request->validated();
            $this->closedMonthGuard->assertTransactionPayloadOpen($user, $validated);

            $transaction = $this->transactionService->createTransaction(
                $validated,
                $user
            );

            $this->repaymentService->handleRepaymentForTransaction($transaction, $validated);

            return response()->json(
                $transaction->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund', 'repaymentLinks.repaidTransaction.category', 'repaymentLinks.mirrorTransaction', 'repaidByLink.repaymentTransaction', 'mirrorRepaymentLink.repaymentTransaction.user']),
                201
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Update an existing transaction.
     *
     * @return JsonResponse
     */
    public function update(StoreTransactionRequest $request, Transaction $transaction)
    {
        $user = auth()->user();

        if ($transaction->user_id !== $user->id && $transaction->family_id !== $user->family_id) {
            abort(403);
        }

        try {
            $validated = $request->validated();
            $this->closedMonthGuard->assertTransactionMutationOpen($transaction, $validated);

            $this->transactionService->updateTransaction($transaction, $validated);

            $this->repaymentService->handleRepaymentForTransaction($transaction, $validated);

            return response()->json(
                $transaction->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund', 'repaymentLinks.repaidTransaction.category', 'repaymentLinks.mirrorTransaction', 'repaidByLink.repaymentTransaction', 'mirrorRepaymentLink.repaymentTransaction.user'])
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Delete a transaction.
     *
     * @return JsonResponse
     */
    public function destroy(Transaction $transaction)
    {
        $user = auth()->user();

        if ($transaction->user_id !== $user->id && $transaction->family_id !== $user->family_id) {
            abort(403);
        }

        try {
            $this->closedMonthGuard->assertTransactionMutationOpen($transaction);
            $this->repaymentService->deleteRepaymentLinks($transaction);
            $this->transactionService->deleteTransaction($transaction);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->noContent();
    }
}
