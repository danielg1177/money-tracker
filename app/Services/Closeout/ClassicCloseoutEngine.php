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
        private CloseoutShortfallCarry $shortfallCarry,
    ) {}

    public function preview(Family $family, int $year, int $month): array
    {
        $family->loadMissing('users');

        $members = [];
        $memberShortfalls = [];
        $broughtForward = 0.0;
        foreach ($family->users as $user) {
            $preview = $this->previewBuilder->classicPreviewForUser($user, $year, $month);
            $members[(string) $user->id] = $preview;
            $memberShortfalls[(string) $user->id] = round((float) ($preview['basis']['shortfall_carried_forward'] ?? 0), 2);
            $broughtForward += (float) ($preview['basis']['shortfall_brought_forward'] ?? 0);
        }

        return [
            'mode' => CloseoutMode::Classic,
            'family' => null,
            'members' => $members,
            'shortfall' => $this->shortfallCarry->snapshot(
                CloseoutMode::Classic,
                array_sum($memberShortfalls),
                $broughtForward,
                $memberShortfalls,
            ),
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
        $allocationPool = $this->previewBuilder->allocationPoolForUser($user, $year, $month);
        $ceiling = max(0.0, $allocationPool['pool']);

        if ($grossIncome <= 0 || $ceiling <= 0) {
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

        $grossRemaining = $grossIncome;

        foreach ($grossRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $allocate = round($grossIncome * $rule->amount / 100, 2);
            } else {
                $allocate = min((float) $rule->amount, $grossRemaining);
            }

            $allocate = $this->previewBuilder->takeFromCeiling($allocate, $ceiling);

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
            $unspentCeiling = round($allocate - $actualAllocated, 2);
            if ($unspentCeiling > 0) {
                $ceiling = round($ceiling + $unspentCeiling, 2);
            }
            $grossRemaining -= $actualAllocated;

            if ($grossRemaining <= 0) {
                break;
            }
        }

        $remainingBasePool = $ceiling;
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
