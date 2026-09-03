<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\FundRule;
use App\Models\User;

class ClassicCloseoutEngine implements CloseoutEngine
{
    public function __construct(
        private CloseoutRulePreviewBuilder $previewBuilder,
        private CloseoutAllocationWriter $allocationWriter,
        private CloseoutTotals $totals,
    ) {}

    public function preview(Family $family, int $year, int $month): array
    {
        $family->loadMissing('users');

        $members = [];
        foreach ($family->users as $user) {
            $members[(string) $user->id] = $this->previewBuilder->classicPreviewForUser($user, $year, $month);
        }

        return [
            'mode' => CloseoutMode::Classic,
            'family' => null,
            'members' => $members,
        ];
    }

    public function apply(Family $family, User $actingUser, int $year, int $month): void
    {
        $family->loadMissing('users');

        foreach ($family->users as $user) {
            $this->applyUserRules($user, $year, $month);
        }
    }

    private function applyUserRules(User $user, int $year, int $month): void
    {
        $grossIncome = $this->previewBuilder->applyGrossIncome($user, $year, $month);

        if ($grossIncome <= 0) {
            return;
        }

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

        $fundAdvanceRemaining = $this->totals->fundAdvanceOutstandingByFundForUserMonth($user, $year, $month);

        $grossRemaining = $grossIncome;
        $grossAllocationsTotal = 0;

        foreach ($grossRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $allocate = round($grossIncome * $rule->amount / 100, 2);
            } else {
                $allocate = min((float) $rule->amount, $grossRemaining);
            }

            if ($allocate <= 0) {
                continue;
            }

            $actualAllocated = $this->allocationWriter->apply(
                $this->commandFromFundRule($rule),
                $user,
                $year,
                $month,
                $allocate,
            );
            $grossRemaining -= $actualAllocated;

            $towardRemainingPool = $actualAllocated;
            if ($rule->destination_type === 'fund' && $rule->destination_id) {
                $fundId = (int) $rule->destination_id;
                if ($fundId > 0) {
                    $outstanding = (float) ($fundAdvanceRemaining[$fundId] ?? 0.0);
                    $towardRemainingPool = max(0.0, $actualAllocated - $outstanding);
                    $fundAdvanceRemaining[$fundId] = max(0.0, $outstanding - $actualAllocated);
                }
            }

            $grossAllocationsTotal += $towardRemainingPool;

            if ($grossRemaining <= 0) {
                break;
            }
        }

        $totalExpenses = $this->totals->expenseTotalTowardRemainingBasis($user, $year, $month);

        $remainingBasePool = $grossIncome - $grossAllocationsTotal - $totalExpenses;
        $remainingAvailablePool = $remainingBasePool;

        if ($remainingAvailablePool > 0) {
            foreach ($remainingRules as $rule) {
                if ($rule->allocation_type === 'percentage') {
                    $projectedAmount = round($remainingBasePool * $rule->amount / 100, 2);
                    $allocate = min($projectedAmount, $remainingAvailablePool);
                } else {
                    $allocate = min((float) $rule->amount, $remainingAvailablePool);
                }

                if ($allocate <= 0) {
                    continue;
                }

                $actualAllocated = $this->allocationWriter->apply(
                    $this->commandFromFundRule($rule),
                    $user,
                    $year,
                    $month,
                    $allocate,
                );
                $remainingAvailablePool -= $actualAllocated;

                if ($remainingAvailablePool <= 0) {
                    break;
                }
            }
        }
    }

    private function commandFromFundRule(FundRule $rule): CloseoutAllocationCommand
    {
        return new CloseoutAllocationCommand(
            name: $rule->name,
            destinationType: $rule->destination_type,
            destinationId: $rule->destination_id ? (int) $rule->destination_id : null,
            destinationTitle: $rule->destination_title,
            closeoutExpenseCategoryId: $rule->closeout_expense_category_id ? (int) $rule->closeout_expense_category_id : null,
            scope: CloseoutScope::User,
            ruleId: $rule->id,
        );
    }
}
