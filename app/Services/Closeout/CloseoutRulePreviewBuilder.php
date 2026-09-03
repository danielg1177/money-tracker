<?php

namespace App\Services\Closeout;

use App\Models\Debt;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\User;

class CloseoutRulePreviewBuilder
{
    public function __construct(private CloseoutTotals $totals) {}

    /**
     * @return list<string>
     */
    public function expenseCloseoutBasisLines(bool $includeRemainingExclusion = true): array
    {
        $lines = [
            'Includes your solo expenses, your split expense shares, and repayments toward tracked debts.',
            'Excludes fund-borrow withdrawals, expenses repaid by another member (`is_repaid`), and expenses created by closeout (so repeat closeouts do not change the basis).',
            'Expense-repayment income (`is_repayment`) is excluded from gross income the same way debt repayments received are excluded.',
        ];

        if ($includeRemainingExclusion) {
            $lines[] = 'Advances marked exclude-from-remaining are excluded from this total; they are settled directly against their target fund at closeout.';
        }

        return $lines;
    }

    /**
     * @return array{basis: array<string, float>, expense_closeout_basis: array{lines: list<string>}, rules: array}
     */
    public function emptyUserPreview(): array
    {
        return [
            'basis' => [
                'gross_income' => 0.0,
                'total_expenses' => 0.0,
                'expense_basis_exclusions' => 0.0,
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

    public function previewGrossIncome(User $user, int $year, int $month): float
    {
        return $this->totals->earnedIncomeForPreview($user, $year, $month);
    }

    public function applyGrossIncome(User $user, int $year, int $month): float
    {
        return $this->previewGrossIncome($user, $year, $month);
    }

    public function expenseBasisExclusions(User $user, int $year, int $month): float
    {
        return (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('exclude_from_expense_basis', true)
            ->whereNotNull('advance_fund_id')
            ->where('is_closeout_initiated', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');
    }

    /**
     * @param  iterable<int, FundRule>  $grossRules
     * @param  iterable<int, FundRule>  $remainingRules
     * @return array<int, float>
     */
    public function previewDebtBalancesForRules(User $user, iterable $grossRules, iterable $remainingRules): array
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
     * @param  array<int, float>  $previewDebtBalances
     */
    public function applyDebtBalanceCap(string $destinationType, ?int $destinationId, float $projectedAmount, array &$previewDebtBalances): float
    {
        if ($destinationType !== 'debt' || ! $destinationId) {
            return $projectedAmount;
        }

        $debtId = (int) $destinationId;
        $available = max(0.0, $previewDebtBalances[$debtId] ?? 0.0);
        $applied = min($projectedAmount, $available);
        $previewDebtBalances[$debtId] = max(0.0, $available - $applied);

        return $applied;
    }

    /**
     * @param  array<int, float>  $fundAdvanceRemaining
     * @return array<string, mixed>
     */
    public function formatFundRuleForPreview(
        FundRule $rule,
        float $projectedAmount,
        float $fundAdvanceOutstandingBefore,
        float $netAfterAdvances,
    ): array {
        return $this->formatRuleRow(
            ruleId: $rule->id,
            ruleName: $rule->name,
            order: (int) $rule->order,
            allocationType: $rule->allocation_type,
            amount: (float) $rule->amount,
            allocationBase: $rule->allocation_base,
            destinationType: $rule->destination_type,
            destinationId: $rule->destination_id ? (int) $rule->destination_id : null,
            destinationTitle: $rule->destination_title,
            projectedAmount: $projectedAmount,
            fundAdvanceOutstandingBefore: $fundAdvanceOutstandingBefore,
            netAfterAdvances: $netAfterAdvances,
            isActive: (bool) $rule->is_active,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function formatRuleRow(
        ?int $ruleId,
        string $ruleName,
        int $order,
        string $allocationType,
        float $amount,
        string $allocationBase,
        string $destinationType,
        ?int $destinationId,
        ?string $destinationTitle,
        float $projectedAmount,
        float $fundAdvanceOutstandingBefore,
        float $netAfterAdvances,
        bool $isActive,
        ?string $stage = null,
    ): array {
        $destinationName = 'Unknown';

        if ($destinationType === 'fund') {
            $destinationName = Fund::query()->find($destinationId)?->name ?? 'Unknown Fund';
        } elseif ($destinationType === 'debt') {
            $debt = Debt::query()->find($destinationId);
            if ($debt) {
                $destinationName = $debt->creditor_name ?? $debt->creditor?->name ?? 'Unknown Debt';
            } else {
                $destinationName = 'Unknown Debt';
            }
        } elseif ($destinationType === 'title') {
            $destinationName = $destinationTitle ?? 'Untitled';
        }

        $row = [
            'rule_id' => $ruleId,
            'rule_name' => $ruleName,
            'order' => $order,
            'allocation_type' => $allocationType,
            'amount' => $amount,
            'allocation_base' => $allocationBase,
            'destination_type' => $destinationType,
            'destination_id' => $destinationId,
            'destination_name' => $destinationName,
            'projected_amount' => $projectedAmount,
            'fund_advance_outstanding_before' => $fundAdvanceOutstandingBefore,
            'net_after_advances' => $netAfterAdvances,
            'is_active' => $isActive,
        ];

        if ($stage !== null) {
            $row['stage'] = $stage;
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ruleResults
     * @param  array<int, float>  $fundAdvanceRemaining
     */
    public function pushFundRulePreviewResult(
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

        $ruleResults[] = $this->formatFundRuleForPreview(
            $rule,
            $previewProjected,
            $outstandingBeforeRounded,
            $netRounded,
        );
    }

    /**
     * Classic per-user dry-run (same contract as the former MonthSummaryController::getRulePreview).
     *
     * @return array{basis: array<string, float>, expense_closeout_basis: array{lines: list<string>}, rules: array}
     */
    public function classicPreviewForUser(User $user, int $year, int $month): array
    {
        $grossIncome = $this->previewGrossIncome($user, $year, $month);

        if ($grossIncome <= 0) {
            return $this->emptyUserPreview();
        }

        $totalExpenses = $this->totals->expenseTotalTowardRemainingBasis($user, $year, $month);
        $expenseBasisExclusions = $this->expenseBasisExclusions($user, $year, $month);

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

        $fundAdvanceRemaining = $this->totals->fundAdvanceOutstandingByFundForUserMonth($user, $year, $month);

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

            $appliedGrossAllocation = $this->applyDebtBalanceCap(
                $rule->destination_type,
                $rule->destination_id ? (int) $rule->destination_id : null,
                $nominalGrossAllocation,
                $previewDebtBalances,
            );

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

            $this->pushFundRulePreviewResult(
                $ruleResults,
                $fundAdvanceRemaining,
                $rule,
                $appliedGrossAllocation,
                ($rule->destination_type === 'debt') ? $nominalGrossAllocation : null,
            );

            if ($grossRemaining <= 0) {
                foreach (array_slice($grossRuleList, $grossRuleIndex + 1) as $remainingGrossRule) {
                    $this->pushFundRulePreviewResult($ruleResults, $fundAdvanceRemaining, $remainingGrossRule, 0.0);
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

            $appliedRemainingAllocation = $this->applyDebtBalanceCap(
                $rule->destination_type,
                $rule->destination_id ? (int) $rule->destination_id : null,
                $nominalRemainingAllocation,
                $previewDebtBalances,
            );

            if ($appliedRemainingAllocation > 0) {
                $remainingAvailablePool -= $appliedRemainingAllocation;
            }

            $this->pushFundRulePreviewResult(
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
                'expense_basis_exclusions' => round($expenseBasisExclusions, 2),
                'non_necessity_expenses' => round($expenseBasisExclusions, 2),
                'gross_allocations_total' => round($grossAllocationsTotal, 2),
                'remaining_after_expenses' => $rawRemaining,
            ],
            'expense_closeout_basis' => [
                'lines' => $this->expenseCloseoutBasisLines(),
            ],
            'rules' => $ruleResults,
        ];
    }
}
