<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\MonthCloseoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonthSummaryController extends Controller
{
    /**
     * Sentinel category id for uncategorized tracked debt repayments in {@see getCategoryTotals()}.
     * Not a real {@see Category} id.
     */
    private const SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID = -1;

    public function __construct(private MonthCloseoutService $monthCloseoutService) {}

    public function show(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        $validated = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = $validated['year'];
        $month = $validated['month'];

        $isHardClosed = $this->monthCloseoutService->isHardClosed($user->family, $year, $month);

        $status = $this->monthCloseoutService->getMonthStatus($user->family, $year, $month);

        $categoryTotals = $this->getCategoryTotals($user, $year, $month);

        $memberBalances = $this->getMemberBalances($user, $year, $month);

        $rulePreview = $this->getRulePreview($user, $year, $month);
        $fundMovements = $this->getFundMovements($user, $year, $month);
        $debtRepayments = $this->getDebtRepaymentsSummary($user, $year, $month);
        $titleSavings = $this->getTitleSavings($user, $year, $month, $isHardClosed);

        return response()->json([
            'year' => $year,
            'month' => $month,
            'is_hard_closed' => $isHardClosed,
            'close_status' => $status,
            'category_totals' => $categoryTotals,
            'category_transactions' => $this->getCategoryTransactions($user, $year, $month),
            'member_balances' => $memberBalances,
            'rule_preview' => $rulePreview,
            'fund_movements' => $fundMovements,
            'debt_repayments' => $debtRepayments,
            'title_savings' => $titleSavings,
        ]);
    }

    /**
     * Debt repayment activity for the viewer in this month (categorized repayments also roll into **category_totals** under their category; uncategorized into **Uncategorized Debt Payments**;
     * amounts count toward closeout expense basis; excluded from closeout **gross** income).
     * Split debt-payment expenses list each participant's portion (split row amount), not only the payer's transaction total.
     *
     * @return array{
     *     paid: array<int, array{
     *         id: int,
     *         amount: float,
     *         transaction_date: string,
     *         description: string|null,
     *         counterparty_label: string|null,
     *         debt_id: int,
     *     }>,
     *     received: array<int, array<string, mixed>>
     * }
     */
    private function getDebtRepaymentsSummary(User $user, int $year, int $month): array
    {
        $paid = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('type', 'expense')
            ->where('is_debt_payment', true)
            ->whereNotNull('debt_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->where(function ($q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhereHas('splits', fn ($sq) => $sq->where('user_id', $user->id));
            })
            ->with(['debt.creditor', 'debt.debtor', 'debt.fund', 'splits'])
            ->orderBy('transaction_date')
            ->get()
            ->map(fn (Transaction $tx) => $this->serializeDebtRepaymentTransaction($tx, $user))
            ->filter(fn (array $row) => abs((float) $row['amount']) >= 0.005)
            ->values()
            ->all();

        $received = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_debt_payment', true)
            ->whereNotNull('debt_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['debt.creditor', 'debt.debtor', 'debt.fund', 'paidByUser'])
            ->orderBy('transaction_date')
            ->get()
            ->map(fn (Transaction $tx) => $this->serializeDebtRepaymentTransaction($tx, $user))->all();

        return [
            'paid' => $paid,
            'received' => $received,
        ];
    }

    /**
     * Return CloseoutTitleSaving records for the authenticated user in this month.
     *
     * Only returns data for hard-closed months, since title savings are created during hard close.
     *
     * @return array<int, array{id: int, title: string, amount: float, is_completed: bool, completed_at: string|null}>
     */
    private function getTitleSavings(User $user, int $year, int $month, bool $isHardClosed): array
    {
        if (! $isHardClosed) {
            return [];
        }

        return CloseoutTitleSaving::query()
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('title')
            ->get()
            ->map(fn (CloseoutTitleSaving $saving) => [
                'id' => $saving->id,
                'title' => $saving->title,
                'amount' => round((float) $saving->amount, 2),
                'is_completed' => (bool) $saving->is_completed,
                'completed_at' => $saving->completed_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     amount: float,
     *     transaction_date: string,
     *     description: string|null,
     *     counterparty_label: string|null,
     *     debt_id: int,
     *     role: string,
     * }
     */
    private function serializeDebtRepaymentTransaction(Transaction $tx, User $viewer): array
    {
        $debt = $tx->debt;
        $counterpartyLabel = null;

        if ($debt) {
            if ($tx->type === 'expense') {
                $counterpartyLabel = $debt->creditor_name ?? $debt->creditor?->name ?? $debt->fund?->name ?? $debt->description;
            } else {
                $counterpartyLabel = $debt->debtor?->name ?? $tx->paidByUser?->name;
            }
        }

        $amount = $tx->type === 'expense'
            ? round($this->viewerDebtExpenseAmount($tx, $viewer), 2)
            : round((float) $tx->amount, 2);

        return [
            'id' => $tx->id,
            'amount' => $amount,
            'transaction_date' => $tx->transaction_date instanceof \DateTimeInterface
                ? $tx->transaction_date->format('Y-m-d')
                : (string) $tx->transaction_date,
            'description' => $tx->description,
            'counterparty_label' => $counterpartyLabel ? (string) $counterpartyLabel : null,
            'debt_id' => (int) $tx->debt_id,
            'role' => $tx->type === 'expense' ? 'paid' : 'received',
        ];
    }

    /**
     * Monetary share of an expense-shaped debt-payment row attributed to this viewer for summary display.
     */
    private function viewerDebtExpenseAmount(Transaction $expenseRow, User $viewer): float
    {
        if (! $expenseRow->is_split) {
            return (int) $expenseRow->user_id === (int) $viewer->id ? (float) $expenseRow->amount : 0.0;
        }

        foreach ($expenseRow->splits as $split) {
            if ((int) $split->user_id === (int) $viewer->id) {
                return (float) $split->amount;
            }
        }

        return 0.0;
    }

    /**
     * Return fund in/out activity for the selected month.
     *
     * Includes non-rule movements (borrow, repayment, initial value) and closeout-linked
     * movements by matching either transaction date, movement creation month, or closeout tag.
     *
     * @return array{
     *     totals: array{in: float, out: float, net: float},
     *     by_fund: array<int, array{
     *         fund_id: int,
     *         fund_name: string,
     *         fund_scope: string,
     *         totals: array{in: float, out: float, net: float},
     *         movements: array<int, array{
     *             id: int,
     *             type: string,
     *             amount: float,
     *             direction: string,
     *             signed_amount: float,
     *             description: string|null
     *         }>
     *     }>
     * }
     */
    private function getFundMovements(object $user, int $year, int $month): array
    {
        $monthTagPadded = sprintf('%04d-%02d', $year, $month);
        $monthTagUnpadded = sprintf('%04d-%d', $year, $month);
        $closeoutMovementTypes = ['closeout_allocation', 'advance_settlement'];

        $movements = FundMovement::query()
            ->whereHas('fund', function ($q) use ($user): void {
                $q->where(function ($fundQuery) use ($user): void {
                    $fundQuery->where(function ($personalQuery) use ($user): void {
                        $personalQuery->where('user_id', $user->id)
                            ->whereNull('family_id');
                    })->orWhere('family_id', $user->family_id);
                });
            })
            ->with('fund')
            ->where(function ($q) use ($year, $month, $monthTagPadded, $monthTagUnpadded, $closeoutMovementTypes): void {
                $q->whereHas('transaction', fn ($txQuery) => $txQuery
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                )->orWhere(function ($movementQuery) use ($year, $month): void {
                    $movementQuery->whereNull('transaction_id')
                        ->whereNotIn('type', ['closeout_allocation', 'advance_settlement'])
                        ->whereYear('created_at', $year)
                        ->whereMonth('created_at', $month);
                })->orWhere(function ($movementQuery) use ($closeoutMovementTypes, $monthTagPadded, $monthTagUnpadded): void {
                    $movementQuery->whereIn('type', $closeoutMovementTypes)
                        ->where(function ($descriptionQuery) use ($monthTagPadded, $monthTagUnpadded): void {
                            $descriptionQuery->where('description', 'like', "%({$monthTagPadded})%")
                                ->orWhere('description', 'like', "%({$monthTagUnpadded})%");
                        });
                });
            })
            ->latest('id')
            ->get();

        $totalsIn = 0.0;
        $totalsOut = 0.0;
        $byFund = [];

        foreach ($movements as $movement) {
            $direction = in_array($movement->type, ['borrow', 'advance_settlement'], true)
                ? 'out'
                : 'in';

            $amount = (float) $movement->amount;
            $signedAmount = $direction === 'out' ? -$amount : $amount;

            if ($direction === 'out') {
                $totalsOut += $amount;
            } else {
                $totalsIn += $amount;
            }

            if (! isset($byFund[$movement->fund_id])) {
                $byFund[$movement->fund_id] = [
                    'fund_id' => $movement->fund_id,
                    'fund_name' => $movement->fund?->name ?? 'Unknown Fund',
                    'fund_scope' => $movement->fund?->family_id ? 'family' : 'personal',
                    'totals' => [
                        'in' => 0.0,
                        'out' => 0.0,
                        'net' => 0.0,
                    ],
                    'movements' => [],
                ];
            }

            if ($direction === 'out') {
                $byFund[$movement->fund_id]['totals']['out'] += $amount;
            } else {
                $byFund[$movement->fund_id]['totals']['in'] += $amount;
            }

            $byFund[$movement->fund_id]['movements'][] = [
                'id' => $movement->id,
                'type' => $movement->type,
                'amount' => $amount,
                'direction' => $direction,
                'signed_amount' => $signedAmount,
                'description' => $movement->description,
            ];
        }

        foreach ($byFund as $fundId => $fundData) {
            $byFund[$fundId]['totals']['net'] = $fundData['totals']['in'] - $fundData['totals']['out'];
        }

        return [
            'totals' => [
                'in' => $totalsIn,
                'out' => $totalsOut,
                'net' => $totalsIn - $totalsOut,
            ],
            'by_fund' => array_values($byFund),
        ];
    }

    /**
     * Fetch category totals for the month for the authenticated user only (split expenses use viewer split rows).
     * Viewer incomes exclude **`is_borrow`** rows (fund borrow withdrawals use **`is_borrow`** and stay out of **`rule_preview.basis.gross_income`** — see fund movement / **Fund In/Out**).
     * Non-split viewer expenses exclude **`is_closeout_initiated`** rows (they stay out of **`rule_preview.basis.total_expenses`** and are shown via fund/debt sections instead).
     * Tracked debt repayments with a **category_id** are merged into that expense category. Repayments with **no** category appear under a synthetic **Uncategorized Debt Payments** row ({@see self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID}).
     *
     * @return array<array{category_id: int|null, category_name: string, category_icon: string|null, total: float, transaction_count: int, type: string}>
     */
    private function getCategoryTotals(User $viewer, int $year, int $month): array
    {
        $grouped = [];

        $viewerIncomes = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'income')
            ->where('is_debt_payment', false)
            ->where('is_borrow', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        foreach ($viewerIncomes as $tx) {
            $this->addViewerCategoryAggregate(
                $grouped,
                'income',
                $tx->category_id,
                $tx->category,
                (float) $tx->amount,
                1
            );
        }

        $viewerSoloExpenses = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', false)
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        foreach ($viewerSoloExpenses as $tx) {
            $this->addViewerCategoryAggregate(
                $grouped,
                'expense',
                $tx->category_id,
                $tx->category,
                (float) $tx->amount,
                1
            );
        }

        $viewerSplitShares = TransactionSplit::query()
            ->where('user_id', $viewer->id)
            ->whereHas('transaction', fn ($q) => $q
                ->where('family_id', $viewer->family_id)
                ->where('type', 'expense')
                ->where('is_split', true)
                ->where('is_debt_payment', false)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month))
            ->with(['transaction.category'])
            ->get();

        foreach ($viewerSplitShares as $split) {
            $tx = $split->transaction;
            if (! $tx) {
                continue;
            }

            $this->addViewerCategoryAggregate(
                $grouped,
                'expense',
                $tx->category_id,
                $tx->category,
                (float) $split->amount,
                1
            );
        }

        $this->mergeDebtRepaymentExpenseCategoryTotals($grouped, $viewer, $year, $month);

        $result = array_values($grouped);

        usort($result, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'expense' ? -1 : 1;
            }

            return (float) $b['total'] <=> (float) $a['total'];
        });

        return $result;
    }

    /**
     * @param  array<string, array{type: string, category_id: int|null, category_name: string, category_icon: string|null, total: float, transaction_count: int}>  $grouped
     */
    private function addViewerCategoryAggregate(array &$grouped, string $type, ?int $categoryId, ?Category $category, float $amount, int $countDelta): void
    {
        $key = "{$type}_{$categoryId}";
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'type' => $type,
                'category_id' => $categoryId,
                'category_name' => $category?->name ?? 'Uncategorized',
                'category_icon' => $category?->icon,
                'total' => 0.0,
                'transaction_count' => 0,
            ];
        }
        $grouped[$key]['total'] += $amount;
        $grouped[$key]['transaction_count'] += $countDelta;
    }

    /**
     * Merge tracked debt-repayment **expense** amounts into category totals: rows with a category join that bucket;
     * rows without a category (solo payer or split share on uncategorized parents) roll into {@see self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID}.
     *
     * @param  array<string, array{type: string, category_id: int|null, category_name: string, category_icon: string|null, total: float, transaction_count: int}>  $grouped
     */
    private function mergeDebtRepaymentExpenseCategoryTotals(array &$grouped, User $viewer, int $year, int $month): void
    {
        $soloCategorized = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', true)
            ->where('is_closeout_initiated', false)
            ->whereNotNull('category_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        foreach ($soloCategorized as $tx) {
            $this->addViewerCategoryAggregate(
                $grouped,
                'expense',
                $tx->category_id,
                $tx->category,
                (float) $tx->amount,
                1,
            );
        }

        $soloUncategorizedTotal = (float) Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', true)
            ->where('is_closeout_initiated', false)
            ->whereNull('category_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $soloUncategorizedCount = (int) Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', true)
            ->where('is_closeout_initiated', false)
            ->whereNull('category_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->count();

        $splitShares = TransactionSplit::query()
            ->where('user_id', $viewer->id)
            ->whereHas('transaction', fn ($q) => $q
                ->where('family_id', $viewer->family_id)
                ->where('type', 'expense')
                ->where('is_split', true)
                ->where('is_debt_payment', true)
                ->where('is_closeout_initiated', false)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month))
            ->with(['transaction.category'])
            ->get();

        $splitUncategorizedTotal = 0.0;
        $splitUncategorizedCount = 0;

        foreach ($splitShares as $split) {
            $tx = $split->transaction;
            if (! $tx) {
                continue;
            }

            $share = (float) $split->amount;

            if ($tx->category_id === null) {
                $splitUncategorizedTotal += $share;
                $splitUncategorizedCount++;
            } else {
                $this->addViewerCategoryAggregate(
                    $grouped,
                    'expense',
                    $tx->category_id,
                    $tx->category,
                    $share,
                    1,
                );
            }
        }

        $syntheticTotal = $soloUncategorizedTotal + $splitUncategorizedTotal;
        $syntheticCount = $soloUncategorizedCount + $splitUncategorizedCount;

        if (abs($syntheticTotal) < 0.005 && $syntheticCount === 0) {
            return;
        }

        $grouped['expense_synthetic_debt_payments'] = [
            'type' => 'expense',
            'category_id' => self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID,
            'category_name' => 'Uncategorized Debt Payments',
            'category_icon' => null,
            'total' => round($syntheticTotal, 2),
            'transaction_count' => $syntheticCount,
        ];
    }

    /**
     * Detailed transaction rows grouped by month-summary category bucket.
     *
     * Keys follow "{type}_{categoryId}" where uncategorized is "null" and synthetic debt-payment
     * uncategorized bucket is {@see self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID}.
     *
     * @return array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     is_split: bool,
     *     split_breakdown: array<int, array{
     *         user_id: int,
     *         user_name: string,
     *         share_percentage: float,
     *         amount: float
     *     }>
     * }>>
     */
    private function getCategoryTransactions(User $viewer, int $year, int $month): array
    {
        $grouped = [];

        $viewerIncomes = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'income')
            ->where('is_debt_payment', false)
            ->where('is_borrow', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($viewerIncomes as $tx) {
            $this->addCategoryTransactionRow($grouped, 'income', $tx->category_id, $tx, (float) $tx->amount);
        }

        $viewerSoloExpenses = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', false)
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($viewerSoloExpenses as $tx) {
            $this->addCategoryTransactionRow($grouped, 'expense', $tx->category_id, $tx, (float) $tx->amount);
        }

        $viewerSplitShares = TransactionSplit::query()
            ->where('user_id', $viewer->id)
            ->whereHas('transaction', fn ($q) => $q
                ->where('family_id', $viewer->family_id)
                ->where('type', 'expense')
                ->where('is_split', true)
                ->where('is_debt_payment', false)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month))
            ->with(['transaction.splits.user'])
            ->get();

        foreach ($viewerSplitShares as $split) {
            $tx = $split->transaction;
            if (! $tx) {
                continue;
            }

            $this->addCategoryTransactionRow($grouped, 'expense', $tx->category_id, $tx, (float) $split->amount);
        }

        $this->mergeDebtRepaymentCategoryTransactions($grouped, $viewer, $year, $month);

        return $grouped;
    }

    /**
     * @param  array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     is_split: bool,
     *     split_breakdown: array<int, array{
     *         user_id: int,
     *         user_name: string,
     *         share_percentage: float,
     *         amount: float
     *     }>
     * }>>  $grouped
     */
    private function addCategoryTransactionRow(
        array &$grouped,
        string $type,
        ?int $categoryId,
        Transaction $transaction,
        float $amount,
    ): void {
        $key = "{$type}_".($categoryId === null ? 'null' : (string) $categoryId);
        $grouped[$key] ??= [];

        $grouped[$key][] = [
            'id' => (int) $transaction->id,
            'transaction_date' => $transaction->transaction_date instanceof \DateTimeInterface
                ? $transaction->transaction_date->format('Y-m-d')
                : (string) $transaction->transaction_date,
            'description' => $transaction->description,
            'amount' => round($amount, 2),
            'is_split' => (bool) $transaction->is_split,
            'split_breakdown' => $transaction->is_split ? $this->serializeSplitBreakdown($transaction) : [],
        ];
    }

    /**
     * @param  array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     is_split: bool,
     *     split_breakdown: array<int, array{
     *         user_id: int,
     *         user_name: string,
     *         share_percentage: float,
     *         amount: float
     *     }>
     * }>>  $grouped
     */
    private function mergeDebtRepaymentCategoryTransactions(array &$grouped, User $viewer, int $year, int $month): void
    {
        $soloRows = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', true)
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($soloRows as $tx) {
            $categoryId = $tx->category_id ?? self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID;
            $this->addCategoryTransactionRow($grouped, 'expense', $categoryId, $tx, (float) $tx->amount);
        }

        $splitShares = TransactionSplit::query()
            ->where('user_id', $viewer->id)
            ->whereHas('transaction', fn ($q) => $q
                ->where('family_id', $viewer->family_id)
                ->where('type', 'expense')
                ->where('is_split', true)
                ->where('is_debt_payment', true)
                ->where('is_closeout_initiated', false)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month))
            ->with(['transaction.splits.user'])
            ->get();

        foreach ($splitShares as $split) {
            $tx = $split->transaction;
            if (! $tx) {
                continue;
            }

            $categoryId = $tx->category_id ?? self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID;
            $this->addCategoryTransactionRow($grouped, 'expense', $categoryId, $tx, (float) $split->amount);
        }
    }

    /**
     * @return array<int, array{user_id: int, user_name: string, share_percentage: float, amount: float}>
     */
    private function serializeSplitBreakdown(Transaction $transaction): array
    {
        return $transaction->splits
            ->sortBy('id')
            ->map(fn (TransactionSplit $split) => [
                'user_id' => (int) $split->user_id,
                'user_name' => (string) ($split->user?->name ?? 'Unknown'),
                'share_percentage' => round((float) $split->share_percentage, 2),
                'amount' => round((float) $split->amount, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Net IOUs between the authenticated user and each family member from **split shared expenses**
     * in this calendar month (payer fronts the bill; non-payers’ shares accumulate as owed to/from the payer).
     *
     * Excludes split **debt repayments** and **closeout-initiated** expenses so this reflects bill-splitting
     * only, aligned with viewer split shares in {@see getCategoryTotals()}.
     *
     * @return array<array{user_id: int, user_name: string, net_amount: float, direction: string}>
     */
    private function getMemberBalances(object $user, int $year, int $month): array
    {
        $splitTransactions = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('type', 'expense')
            ->where('is_split', true)
            ->where('is_debt_payment', false)
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
            ->get();

        $netBalances = [];

        foreach ($splitTransactions as $tx) {
            $payerId = $tx->user_id;

            foreach ($tx->splits as $split) {
                if ($split->user_id === $payerId) {
                    continue;
                }

                if ($payerId === $user->id) {
                    $netBalances[$split->user_id] = ($netBalances[$split->user_id] ?? 0) + (float) $split->amount;
                } elseif ($split->user_id === $user->id) {
                    $netBalances[$payerId] = ($netBalances[$payerId] ?? 0) - (float) $split->amount;
                }
            }
        }

        $memberBalances = [];
        foreach ($netBalances as $userId => $netAmount) {
            if (abs($netAmount) < 0.005) {
                continue;
            }

            $splitUser = User::find($userId);

            $memberBalances[] = [
                'user_id' => $userId,
                'user_name' => $splitUser?->name ?? 'Unknown',
                'net_amount' => abs($netAmount),
                'direction' => $netAmount > 0 ? 'they_owe_you' : 'you_owe_them',
            ];
        }

        return $memberBalances;
    }

    /**
     * Human-readable lines describing how {@see MonthCloseoutService::expenseTotalTowardRemainingBasis}
     * builds the expense total used in month-summary preview and hard-close remaining math.
     *
     * @return list<string>
     */
    private function expenseCloseoutBasisLines(): array
    {
        return [
            'Includes your solo expenses, your split expense shares, and repayments toward tracked debts.',
            'Excludes fund-borrow withdrawals and expenses created by closeout (so repeat closeouts do not change the basis).',
            'Non-necessity advance transactions are excluded from this total; they are settled directly against their target fund at closeout.',
        ];
    }

    /**
     * Dry-run the closeout rule math for the current user (read-only).
     *
     * @return array{basis: array<string, float>, expense_closeout_basis: array{lines: list<string>}, rules: array}
     */
    private function getRulePreview(object $user, int $year, int $month): array
    {
        $grossIncome = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_borrow', false)
            ->where('is_debt_payment', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $grossIncome = (float) $grossIncome;

        if ($grossIncome <= 0) {
            return [
                'basis' => [
                    'gross_income' => 0.0,
                    'total_expenses' => 0.0,
                    'non_necessity_expenses' => 0.0,
                    'gross_allocations_total' => 0.0,
                    'remaining_after_expenses' => 0.0,
                ],
                'expense_closeout_basis' => [
                    'lines' => $this->expenseCloseoutBasisLines(),
                ],
                'rules' => [],
            ];
        }

        $totalExpenses = $this->monthCloseoutService->expenseTotalTowardRemainingBasis($user, $year, $month);
        $nonNecessityExpenses = (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_non_necessity', true)
            ->whereNotNull('advance_fund_id')
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $grossRules = FundRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('allocation_base', '!=', 'remaining')
            ->orderBy('order')
            ->get();

        $remainingRules = FundRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('allocation_base', 'remaining')
            ->orderBy('order')
            ->get();

        $previewDebtBalances = $this->previewDebtBalancesForRules($user, $grossRules, $remainingRules);

        $fundAdvanceRemaining = $this->monthCloseoutService->fundAdvanceOutstandingByFundForUserMonth($user, $year, $month);

        $grossRemaining = $grossIncome;
        $grossAllocationsTotal = 0;
        $ruleResults = [];

        $grossRuleList = $grossRules->values()->all();
        foreach ($grossRuleList as $grossRuleIndex => $rule) {
            if ($rule->allocation_type === 'percentage') {
                $nominalGrossAllocation = round($grossIncome * $rule->amount / 100, 2);
            } else {
                $nominalGrossAllocation = min((float) $rule->amount, $grossRemaining);
            }

            $appliedGrossAllocation = $this->applyDebtBalanceCapForRulePreview($rule, $nominalGrossAllocation, $previewDebtBalances);

            $towardRemainingPool = $appliedGrossAllocation;
            if ($rule->destination_type === 'fund' && $rule->destination_id) {
                $fundId = (int) $rule->destination_id;
                if ($fundId > 0) {
                    $outstanding = (float) ($fundAdvanceRemaining[$fundId] ?? 0.0);
                    $towardRemainingPool = max(0.0, $appliedGrossAllocation - $outstanding);
                }
            }

            if ($appliedGrossAllocation > 0) {
                $grossRemaining -= $appliedGrossAllocation;
                $grossAllocationsTotal += $towardRemainingPool;
            }

            $this->pushRulePreviewResult(
                $ruleResults,
                $fundAdvanceRemaining,
                $rule,
                $appliedGrossAllocation,
                ($rule->destination_type === 'debt') ? $nominalGrossAllocation : null,
            );

            if ($grossRemaining <= 0) {
                foreach (array_slice($grossRuleList, $grossRuleIndex + 1) as $remainingGrossRule) {
                    $this->pushRulePreviewResult($ruleResults, $fundAdvanceRemaining, $remainingGrossRule, 0.0);
                }

                break;
            }
        }

        $remainingBasePool = max(0, $grossIncome - $grossAllocationsTotal - $totalExpenses);
        $remainingAvailablePool = $remainingBasePool;

        foreach ($remainingRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $nominalRemainingAllocation = round($remainingBasePool * $rule->amount / 100, 2);
                $nominalRemainingAllocation = min($nominalRemainingAllocation, $remainingAvailablePool);
            } else {
                $nominalRemainingAllocation = min((float) $rule->amount, $remainingAvailablePool);
            }

            $appliedRemainingAllocation = $this->applyDebtBalanceCapForRulePreview($rule, $nominalRemainingAllocation, $previewDebtBalances);

            if ($appliedRemainingAllocation > 0) {
                $remainingAvailablePool -= $appliedRemainingAllocation;
            }

            $this->pushRulePreviewResult(
                $ruleResults,
                $fundAdvanceRemaining,
                $rule,
                $appliedRemainingAllocation,
                ($rule->destination_type === 'debt') ? $nominalRemainingAllocation : null,
            );
        }

        $rawRemaining = round($grossIncome - $grossAllocationsTotal - $totalExpenses, 2);

        return [
            'basis' => [
                'gross_income' => round($grossIncome, 2),
                'total_expenses' => round($totalExpenses, 2),
                'non_necessity_expenses' => round($nonNecessityExpenses, 2),
                'gross_allocations_total' => round($grossAllocationsTotal, 2),
                'remaining_after_expenses' => $rawRemaining,
            ],
            'expense_closeout_basis' => [
                'lines' => $this->expenseCloseoutBasisLines(),
            ],
            'rules' => $ruleResults,
        ];
    }

    /**
     * Snapshot of debt balances for closeout preview, decremented across gross then remaining rules
     * so multiple rules targeting the same debt match {@see MonthCloseoutService::allocateToDebt()} order.
     *
     * @param  iterable<int, FundRule>  $grossRules
     * @param  iterable<int, FundRule>  $remainingRules
     * @return array<int, float>
     */
    private function previewDebtBalancesForRules(object $user, iterable $grossRules, iterable $remainingRules): array
    {
        $merged = collect($grossRules)->merge(collect($remainingRules));
        $debtDestinationIds = $merged
            ->where('destination_type', 'debt')
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($debtDestinationIds === []) {
            return [];
        }

        $debts = Debt::query()
            ->where('family_id', $user->family_id)
            ->whereIn('id', $debtDestinationIds)
            ->get()
            ->keyBy('id');

        $balances = [];

        foreach ($debtDestinationIds as $debtId) {
            $debt = $debts->get($debtId);
            $balances[$debtId] = ($debt && (float) $debt->balance > 0) ? (float) $debt->balance : 0.0;
        }

        return $balances;
    }

    /**
     * Cap rule output at remaining preview debt balance (same principle as allocateToDebt) and decrement the preview balance.
     *
     * @param  array<int, float>  $previewDebtBalances
     */
    private function applyDebtBalanceCapForRulePreview(FundRule $rule, float $projectedAmount, array &$previewDebtBalances): float
    {
        if ($rule->destination_type !== 'debt' || ! $rule->destination_id) {
            return $projectedAmount;
        }

        $debtId = (int) $rule->destination_id;
        $available = max(0.0, $previewDebtBalances[$debtId] ?? 0.0);
        $applied = min($projectedAmount, $available);
        $previewDebtBalances[$debtId] = max(0.0, $available - $applied);

        return $applied;
    }

    /**
     * @param  array<int, float>  $fundAdvanceRemaining
     * @param  ?float  $debtNominalDisplayed  When set (debt destinations), **`projected_amount`** shows the nominal rule math before the debt-balance cap; **`net_after_advances`** carries the capped amount applied to the debt, matching **`MonthCloseoutService::allocateToDebt`** ledger behavior while keeping basis totals aligned.
     */
    private function pushRulePreviewResult(
        array &$ruleResults,
        array &$fundAdvanceRemaining,
        FundRule $rule,
        float $allocationAmountApplied,
        ?float $debtNominalDisplayed = null,
    ): void {
        $previewProjected = $debtNominalDisplayed ?? $allocationAmountApplied;

        $outstandingBeforeRounded = 0.0;
        $netRounded = round($allocationAmountApplied, 2);

        if ($rule->destination_type === 'fund' && $rule->destination_id) {
            $fundId = (int) $rule->destination_id;
            if ($fundId > 0) {
                $outstandingBefore = (float) ($fundAdvanceRemaining[$fundId] ?? 0.0);
                $netRounded = round($allocationAmountApplied - $outstandingBefore, 2);
                $outstandingBeforeRounded = round($outstandingBefore, 2);
                $fundAdvanceRemaining[$fundId] = max(0.0, $outstandingBefore - $allocationAmountApplied);
            }
        }

        $ruleResults[] = $this->formatRuleForPreview(
            $rule,
            $previewProjected,
            $outstandingBeforeRounded,
            $netRounded,
        );
    }

    /**
     * Format a rule result for preview output.
     *
     * For debt destinations, projected_amount reflects nominal rule allocation (before debt-balance cap) and net_after_advances carries the capped payoff.
     *
     * @return array{rule_id: int, rule_name: string, order: int, allocation_type: string, amount: float, allocation_base: string, destination_type: string, destination_name: string, projected_amount: float, fund_advance_outstanding_before: float, net_after_advances: float, is_active: bool}
     */
    private function formatRuleForPreview(FundRule $rule, float $projectedAmount, float $fundAdvanceOutstandingBefore, float $netAfterAdvances): array
    {
        $destinationName = 'Unknown';

        if ($rule->destination_type === 'fund') {
            $destinationName = Fund::find($rule->destination_id)?->name ?? 'Unknown Fund';
        } elseif ($rule->destination_type === 'debt') {
            $debt = Debt::find($rule->destination_id);
            if ($debt) {
                $destinationName = $debt->creditor_name ?? $debt->creditor?->name ?? 'Unknown Debt';
            } else {
                $destinationName = 'Unknown Debt';
            }
        } elseif ($rule->destination_type === 'title') {
            $destinationName = $rule->destination_title;
        }

        return [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'order' => $rule->order,
            'allocation_type' => $rule->allocation_type,
            'amount' => (float) $rule->amount,
            'allocation_base' => $rule->allocation_base,
            'destination_type' => $rule->destination_type,
            'destination_name' => $destinationName,
            'projected_amount' => $projectedAmount,
            'fund_advance_outstanding_before' => $fundAdvanceOutstandingBefore,
            'net_after_advances' => $netAfterAdvances,
            'is_active' => $rule->is_active,
        ];
    }
}
