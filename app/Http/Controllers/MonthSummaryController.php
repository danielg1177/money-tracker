<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Fund;
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

        return response()->json([
            'year' => $year,
            'month' => $month,
            'is_hard_closed' => $isHardClosed,
            'close_status' => $status,
            'category_totals' => $categoryTotals,
            'member_balances' => $memberBalances,
            'rule_preview' => $rulePreview,
        ]);
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
