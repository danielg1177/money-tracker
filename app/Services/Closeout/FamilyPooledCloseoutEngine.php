<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use App\Models\FundRule;
use App\Models\User;
use Illuminate\Support\Collection;

class FamilyPooledCloseoutEngine implements CloseoutEngine
{
    public function __construct(
        private CloseoutRulePreviewBuilder $previewBuilder,
        private CloseoutAllocationWriter $allocationWriter,
        private CloseoutTotals $totals,
    ) {}

    public function preview(Family $family, int $year, int $month): array
    {
        return $this->compute($family, $year, $month, apply: false, actingUser: null);
    }

    public function apply(Family $family, User $actingUser, int $year, int $month): void
    {
        $this->compute($family, $year, $month, apply: true, actingUser: $actingUser);
    }

    /**
     * @return array{mode: string, family: array<string, mixed>, members: array<string, array<string, mixed>>}
     */
    private function compute(Family $family, int $year, int $month, bool $apply, ?User $actingUser): array
    {
        $family->loadMissing('users');

        $earnedIncome = $this->totals->familyEarnedIncome($family, $year, $month);
        $necessaryExpenses = $this->totals->familyNecessaryExpenses($family, $year, $month);
        $allExpenses = $this->totals->familyAllExpenses($family, $year, $month);
        $nonNecessity = $this->totals->familyNonNecessityExpenses($family, $year, $month);

        $charityBase = round($earnedIncome - $necessaryExpenses, 2);

        $surplusRules = FamilyCloseoutRule::query()
            ->where('family_id', $family->id)
            ->where('is_active', true)
            ->where('stage', FamilyCloseoutRule::StageSurplus)
            ->orderBy('order')
            ->get();

        $remainingRules = FamilyCloseoutRule::query()
            ->where('family_id', $family->id)
            ->where('is_active', true)
            ->where('stage', FamilyCloseoutRule::StageRemainingAfterCharity)
            ->orderBy('order')
            ->get();

        $previewDebtBalances = $this->familyDebtBalances($surplusRules, $remainingRules, $family->users);

        $surplusRuleRows = [];
        $surplusAllocationsTotal = 0.0;
        $surplusRemaining = max(0.0, $charityBase);

        foreach ($surplusRules as $rule) {
            $nominal = 0.0;
            $applied = 0.0;

            if ($charityBase > 0) {
                if ($rule->allocation_type === 'percentage') {
                    $nominal = round($charityBase * (float) $rule->amount / 100, 2);
                    $applied = min($nominal, $surplusRemaining);
                } else {
                    $nominal = min((float) $rule->amount, $surplusRemaining);
                    $applied = $nominal;
                }

                $applied = $this->previewBuilder->applyDebtBalanceCap(
                    $rule->destination_type,
                    $rule->destination_id ? (int) $rule->destination_id : null,
                    $applied,
                    $previewDebtBalances,
                );
            }

            if ($apply && $actingUser && $applied > 0) {
                $applied = $this->allocationWriter->apply(
                    $this->commandFromFamilyRule($rule),
                    $actingUser,
                    $year,
                    $month,
                    $applied,
                );
            }

            if ($applied > 0) {
                $surplusRemaining -= $applied;
                $surplusAllocationsTotal += $applied;
            }

            $surplusRuleRows[] = $this->previewBuilder->formatRuleRow(
                ruleId: $rule->id,
                ruleName: $rule->name,
                order: (int) $rule->order,
                allocationType: $rule->allocation_type,
                amount: (float) $rule->amount,
                allocationBase: FamilyCloseoutRule::StageSurplus,
                destinationType: $rule->destination_type,
                destinationId: $rule->destination_id ? (int) $rule->destination_id : null,
                destinationTitle: $rule->destination_title,
                projectedAmount: $nominal,
                fundAdvanceOutstandingBefore: 0.0,
                netAfterAdvances: $applied,
                isActive: (bool) $rule->is_active,
                stage: FamilyCloseoutRule::StageSurplus,
            );
        }

        $remainingAfterCharity = round($earnedIncome - $allExpenses - $surplusAllocationsTotal, 2);

        $remainingRuleRows = [];
        $remainingAllocationsTotal = 0.0;
        $remainingAvailable = max(0.0, $remainingAfterCharity);
        $remainingBase = max(0.0, $remainingAfterCharity);

        foreach ($remainingRules as $rule) {
            $nominal = 0.0;
            $applied = 0.0;

            if ($remainingAfterCharity > 0) {
                if ($rule->allocation_type === 'percentage') {
                    $nominal = round($remainingBase * (float) $rule->amount / 100, 2);
                    $applied = min($nominal, $remainingAvailable);
                } else {
                    $nominal = min((float) $rule->amount, $remainingAvailable);
                    $applied = $nominal;
                }

                $applied = $this->previewBuilder->applyDebtBalanceCap(
                    $rule->destination_type,
                    $rule->destination_id ? (int) $rule->destination_id : null,
                    $applied,
                    $previewDebtBalances,
                );
            }

            if ($apply && $actingUser && $applied > 0) {
                $applied = $this->allocationWriter->apply(
                    $this->commandFromFamilyRule($rule),
                    $actingUser,
                    $year,
                    $month,
                    $applied,
                );
            }

            if ($applied > 0) {
                $remainingAvailable -= $applied;
                $remainingAllocationsTotal += $applied;
            }

            $remainingRuleRows[] = $this->previewBuilder->formatRuleRow(
                ruleId: $rule->id,
                ruleName: $rule->name,
                order: (int) $rule->order,
                allocationType: $rule->allocation_type,
                amount: (float) $rule->amount,
                allocationBase: FamilyCloseoutRule::StageRemainingAfterCharity,
                destinationType: $rule->destination_type,
                destinationId: $rule->destination_id ? (int) $rule->destination_id : null,
                destinationTitle: $rule->destination_title,
                projectedAmount: $nominal,
                fundAdvanceOutstandingBefore: 0.0,
                netAfterAdvances: $applied,
                isActive: (bool) $rule->is_active,
                stage: FamilyCloseoutRule::StageRemainingAfterCharity,
            );
        }

        $leftover = round(max(0.0, $remainingAfterCharity) - $remainingAllocationsTotal, 2);
        if ($leftover < 0) {
            $leftover = 0.0;
        }

        $splitMembers = $this->leftoverSplitMembers($family, $year, $month, $leftover);

        $members = [];
        foreach ($family->users as $user) {
            $memberRow = collect($splitMembers)->firstWhere('user_id', $user->id);
            $memberPool = (float) ($memberRow['member_pool'] ?? 0.0);
            $members[(string) $user->id] = $this->personalLeftoverPreviewAndApply(
                $user,
                $year,
                $month,
                $memberPool,
                $previewDebtBalances,
                $apply,
            );
        }

        return [
            'mode' => CloseoutMode::FamilyPooled,
            'family' => [
                'basis' => [
                    'earned_income' => $earnedIncome,
                    'necessary_expenses' => $necessaryExpenses,
                    'all_expenses' => $allExpenses,
                    'non_necessity_expenses' => $nonNecessity,
                    'charity_base' => $charityBase,
                    'surplus_allocations_total' => round($surplusAllocationsTotal, 2),
                    'remaining_after_charity' => $remainingAfterCharity,
                    'remaining_allocations_total' => round($remainingAllocationsTotal, 2),
                    'leftover' => $leftover,
                ],
                'surplus_rules' => $surplusRuleRows,
                'remaining_rules' => $remainingRuleRows,
                'leftover_split' => [
                    'members' => $splitMembers,
                ],
            ],
            'members' => $members,
        ];
    }

    /**
     * @param  Collection<int, FamilyCloseoutRule>  $surplusRules
     * @param  Collection<int, FamilyCloseoutRule>  $remainingRules
     * @param  Collection<int, User>  $users
     * @return array<int, float>
     */
    private function familyDebtBalances($surplusRules, $remainingRules, $users): array
    {
        $familyRules = $surplusRules->concat($remainingRules);
        $personalRemaining = FundRule::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->where('is_active', true)
            ->where('allocation_base', 'remaining')
            ->get();

        $ids = $familyRules
            ->concat($personalRemaining)
            ->where('destination_type', 'debt')
            ->pluck('destination_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $firstUser = $users->first();
        if (! $firstUser) {
            return [];
        }

        $dummyGross = collect();
        $dummyRemaining = $personalRemaining->concat($familyRules)->filter(
            fn ($rule) => ($rule->destination_type ?? null) === 'debt'
        );

        return $this->previewBuilder->previewDebtBalancesForRules($firstUser, $dummyGross, $dummyRemaining);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leftoverSplitMembers(Family $family, int $year, int $month, float $leftover): array
    {
        $rows = [];
        $weightSum = 0.0;

        foreach ($family->users as $user) {
            $income = $this->totals->earnedIncomeForPreview($user, $year, $month);
            $splitSpend = $this->totals->splitSpend($user, $year, $month);
            $burden = 1.0;
            $weight = 0.0;

            if ($income > 0) {
                $burden = min(1.0, $splitSpend / $income);
                $weight = max(0.0, 1.0 - $burden);
            }

            $weightSum += $weight;
            $rows[] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'earned_income' => round($income, 2),
                'split_spend' => round($splitSpend, 2),
                'burden' => round($burden, 4),
                'weight' => round($weight, 4),
                'share' => 0.0,
                'member_pool' => 0.0,
            ];
        }

        if ($weightSum <= 0 || $leftover <= 0) {
            return $rows;
        }

        $assigned = 0.0;
        $lastIndex = count($rows) - 1;

        foreach ($rows as $index => &$row) {
            $share = $row['weight'] / $weightSum;
            $row['share'] = round($share, 4);

            if ($index === $lastIndex) {
                $row['member_pool'] = round($leftover - $assigned, 2);
            } else {
                $pool = round($leftover * $share, 2);
                $row['member_pool'] = $pool;
                $assigned += $pool;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<int, float>  $previewDebtBalances
     * @return array{basis: array<string, float>, expense_closeout_basis: array{lines: list<string>}, rules: array}
     */
    private function personalLeftoverPreviewAndApply(
        User $user,
        int $year,
        int $month,
        float $memberPool,
        array &$previewDebtBalances,
        bool $apply,
    ): array {
        $grossIncome = $this->previewBuilder->previewGrossIncome($user, $year, $month);
        $totalExpenses = $this->totals->expenseTotalTowardRemainingBasis($user, $year, $month, applyExpenseBasisExclusion: false);

        $remainingRules = FundRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('allocation_base', 'remaining')
            ->orderBy('order')
            ->get();

        $fundAdvanceRemaining = $this->totals->fundAdvanceOutstandingByFundForUserMonth($user, $year, $month);
        $ruleResults = [];
        $remainingAvailablePool = max(0.0, $memberPool);
        $remainingBasePool = max(0.0, $memberPool);

        foreach ($remainingRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $nominalRemainingAllocation = round($remainingBasePool * $rule->amount / 100, 2);
                $nominalRemainingAllocation = min($nominalRemainingAllocation, $remainingAvailablePool);
            } else {
                $nominalRemainingAllocation = min((float) $rule->amount, $remainingAvailablePool);
            }

            $appliedRemainingAllocation = $this->previewBuilder->applyDebtBalanceCap(
                $rule->destination_type,
                $rule->destination_id ? (int) $rule->destination_id : null,
                $nominalRemainingAllocation,
                $previewDebtBalances,
            );

            if ($apply && $appliedRemainingAllocation > 0) {
                $appliedRemainingAllocation = $this->allocationWriter->apply(
                    new CloseoutAllocationCommand(
                        name: $rule->name,
                        destinationType: $rule->destination_type,
                        destinationId: $rule->destination_id ? (int) $rule->destination_id : null,
                        destinationTitle: $rule->destination_title,
                        closeoutExpenseCategoryId: $rule->closeout_expense_category_id ? (int) $rule->closeout_expense_category_id : null,
                        scope: CloseoutScope::User,
                        ruleId: $rule->id,
                    ),
                    $user,
                    $year,
                    $month,
                    $appliedRemainingAllocation,
                );
            }

            if ($appliedRemainingAllocation > 0) {
                $remainingAvailablePool -= $appliedRemainingAllocation;
            }

            $this->previewBuilder->pushFundRulePreviewResult(
                $ruleResults,
                $fundAdvanceRemaining,
                $rule,
                $appliedRemainingAllocation,
                ($rule->destination_type === 'debt') ? $nominalRemainingAllocation : null,
            );
        }

        return [
            'basis' => [
                'gross_income' => round($grossIncome, 2),
                'total_expenses' => round($totalExpenses, 2),
                'expense_basis_exclusions' => 0.0,
                'non_necessity_expenses' => 0.0,
                'gross_allocations_total' => 0.0,
                'remaining_after_expenses' => round($memberPool, 2),
                'member_pool' => round($memberPool, 2),
            ],
            'expense_closeout_basis' => [
                'lines' => [
                    'Family pooled closeout: leftover after family rules is split among members, then your remaining-base rules run on your share.',
                    'Personal gross/net closeout rules are skipped in this mode.',
                    'Exclude-from-remaining is a classic-closeout flag and does not change family pooled math.',
                ],
            ],
            'rules' => $ruleResults,
        ];
    }

    private function commandFromFamilyRule(FamilyCloseoutRule $rule): CloseoutAllocationCommand
    {
        return new CloseoutAllocationCommand(
            name: $rule->name,
            destinationType: $rule->destination_type,
            destinationId: $rule->destination_id ? (int) $rule->destination_id : null,
            destinationTitle: $rule->destination_title,
            closeoutExpenseCategoryId: $rule->closeout_expense_category_id ? (int) $rule->closeout_expense_category_id : null,
            scope: CloseoutScope::Family,
            ruleId: null,
        );
    }
}
