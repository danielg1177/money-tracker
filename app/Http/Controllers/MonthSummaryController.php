<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\MonthHardClose;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Closeout\CloseoutArtifactReconstructor;
use App\Services\Closeout\CloseoutEngineResolver;
use App\Services\Closeout\CloseoutMode;
use App\Services\Closeout\CloseoutRulePreviewBuilder;
use App\Services\MonthCloseoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MonthSummaryController extends Controller
{
    /**
     * Sentinel category id for uncategorized tracked debt repayments in {@see getCategoryTotals()}.
     * Not a real {@see Category} id.
     */
    private const SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID = -1;

    public function __construct(
        private MonthCloseoutService $monthCloseoutService,
        private CloseoutEngineResolver $closeoutEngineResolver,
        private CloseoutArtifactReconstructor $artifactReconstructor,
        private CloseoutRulePreviewBuilder $rulePreviewBuilder,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = Auth::user();

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

        $closeoutResolved = $this->resolveCloseoutComputation($user, $year, $month, $isHardClosed);
        $rulePreview = $this->viewerRulePreview($closeoutResolved['computation'], $user);
        $fundMovements = $this->getFundMovements($user, $year, $month);
        $debtRepayments = $this->getDebtRepaymentsSummary($user, $year, $month);
        $titleSavings = $this->getTitleSavings($user, $year, $month, $isHardClosed);

        $payload = [
            'year' => $year,
            'month' => $month,
            'is_hard_closed' => $isHardClosed,
            'close_status' => $status,
            'category_totals' => $categoryTotals,
            'category_transactions' => $this->getCategoryTransactions($user, $year, $month),
            'member_balances' => $memberBalances,
            'rule_preview' => $rulePreview,
            'closeout_preview' => [
                'mode' => $closeoutResolved['mode'],
                'source' => $closeoutResolved['source'],
                'family' => $closeoutResolved['computation']['family'] ?? null,
            ],
            'fund_advance_transactions' => $this->getFundAdvanceTransactions($user, $year, $month),
            'fund_movements' => $fundMovements,
            'debt_repayments' => $debtRepayments,
            'title_savings' => $titleSavings,
        ];

        if ($user->view_family_expenses) {
            $payload['family_category_totals'] = $this->getFamilyCategoryTotals($user, $year, $month);
            $payload['family_category_transactions'] = $this->getFamilyCategoryTransactions($user, $year, $month);
            $payload['debt_repayments']['family_debt_paid'] = $this->getFamilyDebtPaidSummary($user, $year, $month);
        }

        return response()->json($payload);
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
     *         is_family_debt: bool,
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
            'is_family_debt' => (bool) ($debt?->is_family_debt),
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
     * Household payments toward family-shared debts (`is_family_debt`) this month.
     * Split payments count once at the full transaction amount for the family total.
     *
     * @return array<int, array{debt_id: int, counterparty_label: string|null, you_amount: float, family_amount: float}>
     */
    private function getFamilyDebtPaidSummary(User $viewer, int $year, int $month): array
    {
        $payments = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'expense')
            ->where('is_debt_payment', true)
            ->whereNotNull('debt_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->whereHas('debt', fn ($q) => $q->where('is_family_debt', true))
            ->with(['debt.creditor', 'debt.debtor', 'debt.fund', 'splits'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($payments as $tx) {
            $debtId = (int) $tx->debt_id;
            $debt = $tx->debt;
            $grouped[$debtId] ??= [
                'debt_id' => $debtId,
                'counterparty_label' => $debt
                    ? (string) ($debt->creditor_name ?? $debt->creditor?->name ?? $debt->fund?->name ?? $debt->description ?? '')
                    : null,
                'you_amount' => 0.0,
                'family_amount' => 0.0,
            ];

            $grouped[$debtId]['family_amount'] += (float) $tx->amount;
            $grouped[$debtId]['you_amount'] += $this->viewerDebtExpenseAmount($tx, $viewer);
        }

        return collect($grouped)
            ->map(fn (array $row) => [
                'debt_id' => $row['debt_id'],
                'counterparty_label' => $row['counterparty_label'] !== '' ? $row['counterparty_label'] : null,
                'you_amount' => round($row['you_amount'], 2),
                'family_amount' => round($row['family_amount'], 2),
            ])
            ->filter(fn (array $row) => abs((float) $row['family_amount']) >= 0.005)
            ->values()
            ->all();
    }

    /**
     * Return fund in/out activity for the selected month.
     *
     * Includes non-rule movements (borrow, repayment, initial value) and closeout-linked
     * movements by matching either transaction date, movement creation month, or closeout tag.
     * Excludes `savings_sweep` and `manual_override` (fund-page history only).
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
            ->whereNotIn('type', ['savings_sweep', 'manual_override'])
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
     * Viewer incomes exclude **`is_borrow`** and **`is_repayment`** rows (fund borrows and expense-repayment income stay out of **`rule_preview.basis.gross_income`** — see fund movement / **Fund In/Out** / repayment UI).
     * Non-split viewer expenses exclude **`is_closeout_initiated`** and **`is_repaid`** rows (closeout ledger lines and expenses repaid by another member stay out of **`rule_preview.basis.total_expenses`**).
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
            ->whereNotRepaymentIncome()
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
            ->whereNotRepaidExpense()
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
                ->whereNotRepaidExpense()
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
     * Household-scoped category totals for family expense view. Split expenses count once at
     * the full transaction amount (not summed split shares). Viewer {@see getCategoryTotals()}
     * is unchanged. Inter-member debt payments (one family member paying another) are omitted
     * from family expense totals because they are internal transfers, not household spending.
     *
     * @return array<int, array{type: string, category_id: int|null, category_name: string, category_icon: string|null, total: float, transaction_count: int}>
     */
    private function getFamilyCategoryTotals(User $viewer, int $year, int $month): array
    {
        $grouped = [];

        $familyIncomes = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'income')
            ->where('is_debt_payment', false)
            ->where('is_borrow', false)
            ->whereNotRepaymentIncome()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        foreach ($familyIncomes as $tx) {
            $this->addViewerCategoryAggregate(
                $grouped,
                'income',
                $tx->category_id,
                $tx->category,
                (float) $tx->amount,
                1
            );
        }

        $familySoloExpenses = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', false)
            ->where('is_closeout_initiated', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        foreach ($familySoloExpenses as $tx) {
            $this->addViewerCategoryAggregate(
                $grouped,
                'expense',
                $tx->category_id,
                $tx->category,
                (float) $tx->amount,
                1
            );
        }

        $familySplitExpenses = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'expense')
            ->where('is_split', true)
            ->where('is_debt_payment', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        foreach ($familySplitExpenses as $tx) {
            $this->addViewerCategoryAggregate(
                $grouped,
                'expense',
                $tx->category_id,
                $tx->category,
                (float) $tx->amount,
                1
            );
        }

        $this->mergeFamilyDebtRepaymentExpenseCategoryTotals($grouped, $viewer, $year, $month);

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
     * Merge household debt-repayment expenses into family category totals. Split repayments
     * count once at the full parent amount. Inter-member payments (`debt.creditor_id` set)
     * are excluded; external and fund debt payments still count as family spending.
     *
     * @param  array<string, array{type: string, category_id: int|null, category_name: string, category_icon: string|null, total: float, transaction_count: int}>  $grouped
     */
    private function mergeFamilyDebtRepaymentExpenseCategoryTotals(array &$grouped, User $viewer, int $year, int $month): void
    {
        $soloCategorized = $this->familyExternalDebtPaymentExpensesQuery($viewer, $year, $month)
            ->where('is_split', false)
            ->whereNotNull('category_id')
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

        $soloUncategorizedTotal = (float) $this->familyExternalDebtPaymentExpensesQuery($viewer, $year, $month)
            ->where('is_split', false)
            ->whereNull('category_id')
            ->sum('amount');

        $soloUncategorizedCount = (int) $this->familyExternalDebtPaymentExpensesQuery($viewer, $year, $month)
            ->where('is_split', false)
            ->whereNull('category_id')
            ->count();

        $splitParents = $this->familyExternalDebtPaymentExpensesQuery($viewer, $year, $month)
            ->where('is_split', true)
            ->with('category')
            ->get();

        $splitUncategorizedTotal = 0.0;
        $splitUncategorizedCount = 0;

        foreach ($splitParents as $tx) {
            if ($tx->category_id === null) {
                $splitUncategorizedTotal += (float) $tx->amount;
                $splitUncategorizedCount++;
            } else {
                $this->addViewerCategoryAggregate(
                    $grouped,
                    'expense',
                    $tx->category_id,
                    $tx->category,
                    (float) $tx->amount,
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
            ->whereNotRepaidExpense()
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
            ->whereNotRepaidExpense()
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
            ->whereNotRepaidExpense()
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
                ->whereNotRepaidExpense()
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
     *     user_id: int|null,
     *     user_name: string|null,
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
            ->whereNotRepaymentIncome()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
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
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
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
                ->whereNotRepaidExpense()
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month))
            ->with(['transaction.splits.user', 'transaction.user'])
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
     * Unique household transactions per category bucket for family expense view.
     * Split expenses appear once at the full transaction amount. Inter-member debt
     * payments are omitted (same rule as {@see getFamilyCategoryTotals()}).
     *
     * @return array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     is_split: bool,
     *     user_id: int|null,
     *     user_name: string|null,
     *     split_breakdown: array<int, array{
     *         user_id: int,
     *         user_name: string,
     *         share_percentage: float,
     *         amount: float
     *     }>
     * }>>
     */
    private function getFamilyCategoryTransactions(User $viewer, int $year, int $month): array
    {
        $grouped = [];

        $familyIncomes = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'income')
            ->where('is_debt_payment', false)
            ->where('is_borrow', false)
            ->whereNotRepaymentIncome()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($familyIncomes as $tx) {
            $this->addCategoryTransactionRow($grouped, 'income', $tx->category_id, $tx, (float) $tx->amount);
        }

        $familySoloExpenses = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', false)
            ->where('is_closeout_initiated', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($familySoloExpenses as $tx) {
            $this->addCategoryTransactionRow($grouped, 'expense', $tx->category_id, $tx, (float) $tx->amount);
        }

        $familySplitExpenses = Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'expense')
            ->where('is_split', true)
            ->where('is_debt_payment', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($familySplitExpenses as $tx) {
            $this->addCategoryTransactionRow($grouped, 'expense', $tx->category_id, $tx, (float) $tx->amount);
        }

        $this->mergeFamilyDebtRepaymentCategoryTransactions($grouped, $viewer, $year, $month);

        return $grouped;
    }

    /**
     * Advance-tagged expense rows per fund for the viewer in this month (same scope as
     * {@see MonthCloseoutService::fundAdvanceOutstandingByFundForUserMonth()}).
     *
     * @return array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     category_name: string|null,
     *     category_icon: string|null,
     *     exclude_from_expense_basis: bool,
     *     is_necessity: bool
     * }>>
     */
    private function getFundAdvanceTransactions(User $viewer, int $year, int $month): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $viewer->id)
            ->where('type', 'expense')
            ->whereNotNull('advance_fund_id')
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category:id,name,icon')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($transactions as $transaction) {
            $fundIdKey = (string) (int) $transaction->advance_fund_id;
            $grouped[$fundIdKey] ??= [];
            $grouped[$fundIdKey][] = [
                'id' => (int) $transaction->id,
                'transaction_date' => $transaction->transaction_date instanceof \DateTimeInterface
                    ? $transaction->transaction_date->format('Y-m-d')
                    : (string) $transaction->transaction_date,
                'description' => $transaction->description,
                'amount' => round((float) $transaction->amount, 2),
                'category_name' => $transaction->category?->name,
                'category_icon' => $transaction->category?->icon,
                'exclude_from_expense_basis' => (bool) $transaction->exclude_from_expense_basis,
                'is_necessity' => (bool) $transaction->is_necessity,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     is_split: bool,
     *     user_id: int|null,
     *     user_name: string|null,
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

        foreach ($grouped[$key] as $existing) {
            if ((int) $existing['id'] === (int) $transaction->id) {
                return;
            }
        }

        $grouped[$key][] = [
            'id' => (int) $transaction->id,
            'transaction_date' => $transaction->transaction_date instanceof \DateTimeInterface
                ? $transaction->transaction_date->format('Y-m-d')
                : (string) $transaction->transaction_date,
            'description' => $transaction->description,
            'amount' => round($amount, 2),
            'is_split' => (bool) $transaction->is_split,
            'user_id' => $transaction->user_id !== null ? (int) $transaction->user_id : null,
            'user_name' => $transaction->user?->name,
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
     *     user_id: int|null,
     *     user_name: string|null,
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
            ->whereNotRepaidExpense()
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['splits.user', 'user'])
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
                ->whereNotRepaidExpense()
                ->where('is_closeout_initiated', false)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month))
            ->with(['transaction.splits.user', 'transaction.user'])
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
     * @param  array<string, array<int, array{
     *     id: int,
     *     transaction_date: string,
     *     description: string|null,
     *     amount: float,
     *     is_split: bool,
     *     user_id: int|null,
     *     user_name: string|null,
     *     split_breakdown: array<int, array{
     *         user_id: int,
     *         user_name: string,
     *         share_percentage: float,
     *         amount: float
     *     }>
     * }>>  $grouped
     */
    private function mergeFamilyDebtRepaymentCategoryTransactions(array &$grouped, User $viewer, int $year, int $month): void
    {
        $rows = $this->familyExternalDebtPaymentExpensesQuery($viewer, $year, $month)
            ->with(['splits.user', 'user'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($rows as $tx) {
            $categoryId = $tx->category_id ?? self::SYNTHETIC_DEBT_PAYMENT_CATEGORY_ID;
            $this->addCategoryTransactionRow($grouped, 'expense', $categoryId, $tx, (float) $tx->amount);
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
            ->where('is_closeout_initiated', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->with(['splits.user', 'user', 'category'])
            ->get();

        $netBalances = [];
        $sourceBreakdown = [];

        foreach ($splitTransactions as $tx) {
            $payerId = (int) $tx->user_id;

            foreach ($tx->splits as $split) {
                $splitUserId = (int) $split->user_id;
                if ($splitUserId === $payerId) {
                    continue;
                }

                if ($payerId === $user->id) {
                    $counterpartyId = $splitUserId;
                    $shareAmount = (float) $split->amount;
                    $netBalances[$counterpartyId] = ($netBalances[$counterpartyId] ?? 0) + $shareAmount;
                    $this->accumulateSplitBalanceSource(
                        $sourceBreakdown,
                        $counterpartyId,
                        'from_you_created',
                        $tx,
                        $shareAmount
                    );
                } elseif ($splitUserId === $user->id) {
                    $netBalances[$payerId] = ($netBalances[$payerId] ?? 0) - (float) $split->amount;
                    $this->accumulateSplitBalanceSource(
                        $sourceBreakdown,
                        $payerId,
                        'from_them_created',
                        $tx,
                        (float) $split->amount
                    );
                }
            }
        }

        $splitUsers = User::query()
            ->whereIn('id', array_keys($netBalances))
            ->get()
            ->keyBy('id');

        $memberBalances = [];
        foreach ($netBalances as $userId => $netAmount) {
            if (abs($netAmount) < 0.005) {
                continue;
            }

            $breakdown = $sourceBreakdown[$userId] ?? [
                'from_you_created_amount' => 0.0,
                'from_them_created_amount' => 0.0,
                'from_you_created_transactions' => [],
                'from_them_created_transactions' => [],
            ];
            $splitUser = $splitUsers->get((int) $userId);

            $memberBalances[] = [
                'user_id' => $userId,
                'user_name' => $splitUser?->name ?? 'Unknown',
                'net_amount' => abs($netAmount),
                'direction' => $netAmount > 0 ? 'they_owe_you' : 'you_owe_them',
                'from_you_created_amount' => round((float) $breakdown['from_you_created_amount'], 2),
                'from_them_created_amount' => round((float) $breakdown['from_them_created_amount'], 2),
                'from_you_created_transactions' => $breakdown['from_you_created_transactions'],
                'from_them_created_transactions' => $breakdown['from_them_created_transactions'],
            ];
        }

        return $memberBalances;
    }

    /**
     * Debt-payment expenses that leave the household (external creditor or fund).
     * One family member paying another (`debts.creditor_id` set) is an internal transfer
     * and is omitted from family overlay expense totals.
     *
     * @return Builder<Transaction>
     */
    private function familyExternalDebtPaymentExpensesQuery(User $viewer, int $year, int $month): Builder
    {
        return Transaction::query()
            ->where('family_id', $viewer->family_id)
            ->where('type', 'expense')
            ->where('is_debt_payment', true)
            ->whereNotRepaidExpense()
            ->where('is_closeout_initiated', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->whereDoesntHave('debt', function ($debtQuery): void {
                $debtQuery->whereNotNull('creditor_id');
            });
    }

    /**
     * @param  array<int, array{
     *   from_you_created_amount: float,
     *   from_them_created_amount: float,
     *   from_you_created_transactions: array<int, array{
     *     transaction_id: int,
     *     transaction_date: string,
     *     category_name: string,
     *     category_icon: string|null,
     *     description: string|null,
     *     total_amount: float,
     *     balance_amount: float,
     *   }>,
     *   from_them_created_transactions: array<int, array{
     *     transaction_id: int,
     *     transaction_date: string,
     *     category_name: string,
     *     category_icon: string|null,
     *     description: string|null,
     *     total_amount: float,
     *     balance_amount: float,
     *   }>
     * }>  $sourceBreakdown
     */
    private function accumulateSplitBalanceSource(array &$sourceBreakdown, int $counterpartyId, string $sourceKey, Transaction $tx, float $shareAmount): void
    {
        if (! isset($sourceBreakdown[$counterpartyId])) {
            $sourceBreakdown[$counterpartyId] = [
                'from_you_created_amount' => 0.0,
                'from_them_created_amount' => 0.0,
                'from_you_created_transactions' => [],
                'from_them_created_transactions' => [],
            ];
        }

        $amountKey = "{$sourceKey}_amount";
        $transactionsKey = "{$sourceKey}_transactions";
        $sourceBreakdown[$counterpartyId][$amountKey] += $shareAmount;

        $sourceBreakdown[$counterpartyId][$transactionsKey][] = [
            'transaction_id' => (int) $tx->id,
            'transaction_date' => $tx->transaction_date instanceof \DateTimeInterface
                ? $tx->transaction_date->format('Y-m-d')
                : (string) $tx->transaction_date,
            'category_name' => $tx->category?->name ?? 'Uncategorized',
            'category_icon' => $tx->category?->icon,
            'description' => $tx->description,
            'total_amount' => round((float) $tx->amount, 2),
            'balance_amount' => round($shareAmount, 2),
        ];
    }

    /**
     * @return array{computation: array<string, mixed>, source: string, mode: string}
     */
    private function resolveCloseoutComputation(User $user, int $year, int $month, bool $isHardClosed): array
    {
        $hardClose = MonthHardClose::query()
            ->where('family_id', $user->family_id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($hardClose && is_array($hardClose->results_snapshot) && $hardClose->results_snapshot !== []) {
            return [
                'computation' => $hardClose->results_snapshot,
                'source' => 'snapshot',
                'mode' => $hardClose->resolvedCloseoutMode(),
            ];
        }

        if ($isHardClosed) {
            return [
                'computation' => $this->artifactReconstructor->reconstructForUser($user, $year, $month),
                'source' => 'reconstructed',
                'mode' => CloseoutMode::Classic,
            ];
        }

        $user->loadMissing('family.users');
        $computation = $this->closeoutEngineResolver->for($user->family)->preview($user->family, $year, $month);

        return [
            'computation' => $computation,
            'source' => 'live',
            'mode' => $computation['mode'] ?? CloseoutMode::normalize($user->family->closeout_mode),
        ];
    }

    /**
     * @param  array<string, mixed>  $computation
     * @return array{basis: array<string, float>, expense_closeout_basis: array{lines: list<string>}, rules: array}
     */
    private function viewerRulePreview(array $computation, User $user): array
    {
        $members = $computation['members'] ?? [];
        $key = (string) $user->id;

        return $members[$key]
            ?? $members[$user->id]
            ?? $this->rulePreviewBuilder->emptyUserPreview();
    }
}
