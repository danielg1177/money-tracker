<?php

namespace App\Http\Controllers;

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

        $categoryTotals = $this->getCategoryTotals($user->family_id, $year, $month);

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
            'member_balances' => $memberBalances,
            'rule_preview' => $rulePreview,
            'fund_movements' => $fundMovements,
            'debt_repayments' => $debtRepayments,
            'title_savings' => $titleSavings,
        ]);
    }

    /**
     * Debt repayment activity for the viewer in this month (excluded from category totals and closeout gross income).
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
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_debt_payment', true)
            ->whereNotNull('debt_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with(['debt.creditor', 'debt.debtor', 'debt.fund'])
            ->orderBy('transaction_date')
            ->get()
            ->map(fn (Transaction $tx) => $this->serializeDebtRepaymentTransaction($tx))->all();

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
            ->map(fn (Transaction $tx) => $this->serializeDebtRepaymentTransaction($tx))->all();

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
    private function serializeDebtRepaymentTransaction(Transaction $tx): array
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

        return [
            'id' => $tx->id,
            'amount' => round((float) $tx->amount, 2),
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
        $monthTag = sprintf('%04d-%02d', $year, $month);

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
            ->where(function ($q) use ($year, $month, $monthTag): void {
                $q->whereHas('transaction', fn ($txQuery) => $txQuery
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                )->orWhere(function ($movementQuery) use ($year, $month): void {
                    $movementQuery->whereNull('transaction_id')
                        ->whereYear('created_at', $year)
                        ->whereMonth('created_at', $month);
                })->orWhere('description', 'like', "%({$monthTag})%");
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
     * Fetch category totals for the month, excluding debt payments.
     *
     * @return array<array{category_id: int|null, category_name: string, category_icon: string|null, total: float, transaction_count: int, type: string}>
     */
    private function getCategoryTotals(int $familyId, int $year, int $month): array
    {
        $transactions = Transaction::query()
            ->where('family_id', $familyId)
            ->where('is_debt_payment', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('category')
            ->get();

        $grouped = [];
        foreach ($transactions as $tx) {
            $key = "{$tx->type}_{$tx->category_id}";
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'type' => $tx->type,
                    'category_id' => $tx->category_id,
                    'category_name' => $tx->category?->name ?? 'Uncategorized',
                    'category_icon' => $tx->category?->icon,
                    'total' => 0.0,
                    'transaction_count' => 0,
                ];
            }
            $grouped[$key]['total'] += (float) $tx->amount;
            $grouped[$key]['transaction_count']++;
        }

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
     * Calculate member balances from split transactions.
     *
     * @return array<array{user_id: int, user_name: string, net_amount: float, direction: string}>
     */
    private function getMemberBalances(object $user, int $year, int $month): array
    {
        $splitTransactions = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('type', 'expense')
            ->where('is_split', true)
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
     * Dry-run the closeout rule math for the current user (read-only).
     *
     * @return array{basis: array, rules: array}
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
                    'remaining_after_expenses' => 0.0,
                ],
                'rules' => [],
            ];
        }

        $soloExpenses = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', false)
            ->where('is_borrow', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $splitExpenses = TransactionSplit::query()
            ->where('user_id', $user->id)
            ->whereHas('transaction', fn ($q) => $q
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month)
                ->where('type', 'expense')
            )
            ->sum('amount');

        $totalExpenses = (float) $soloExpenses + (float) $splitExpenses;

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

        $grossRemaining = $grossIncome;
        $grossAllocationsTotal = 0;
        $ruleResults = [];

        foreach ($grossRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $projectedAmount = round($grossIncome * $rule->amount / 100, 2);
            } else {
                $projectedAmount = min((float) $rule->amount, $grossRemaining);
            }

            if ($projectedAmount > 0) {
                $grossRemaining -= $projectedAmount;
                $grossAllocationsTotal += $projectedAmount;
            }

            $ruleResults[] = $this->formatRuleForPreview($rule, $projectedAmount);
        }

        $remainingPool = max(0, $grossIncome - $grossAllocationsTotal - $totalExpenses);

        foreach ($remainingRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $projectedAmount = round($remainingPool * $rule->amount / 100, 2);
            } else {
                $projectedAmount = min((float) $rule->amount, $remainingPool);
            }

            if ($projectedAmount > 0) {
                $remainingPool -= $projectedAmount;
            }

            $ruleResults[] = $this->formatRuleForPreview($rule, $projectedAmount);
        }

        return [
            'basis' => [
                'gross_income' => $grossIncome,
                'total_expenses' => $totalExpenses,
                'remaining_after_expenses' => max(0, $grossIncome - $grossAllocationsTotal - $totalExpenses),
            ],
            'rules' => $ruleResults,
        ];
    }

    /**
     * Format a rule result for preview output.
     *
     * @return array{rule_id: int, rule_name: string, order: int, allocation_type: string, amount: float, allocation_base: string, destination_type: string, destination_name: string, projected_amount: float, is_active: bool}
     */
    private function formatRuleForPreview(FundRule $rule, float $projectedAmount): array
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
            'is_active' => $rule->is_active,
        ];
    }
}
