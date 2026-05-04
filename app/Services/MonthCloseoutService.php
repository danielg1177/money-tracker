<?php

namespace App\Services;

use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\MonthHardClose;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MonthCloseoutService
{
    /**
     * Create a soft close record for a user in a given month.
     *
     * When a family has only one member, auto-trigger a hard close immediately.
     *
     * @return array{soft_close: MonthSoftClose, hard_close: MonthHardClose|null}
     *
     * @throws InvalidArgumentException If user already has a soft close or a hard close exists
     */
    public function softClose(User $user, int $year, int $month): array
    {
        $existingSoftClose = MonthSoftClose::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($existingSoftClose) {
            throw new InvalidArgumentException('User already has a soft close for this month.');
        }

        $hardClose = MonthHardClose::query()
            ->where('family_id', $user->family_id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($hardClose) {
            throw new InvalidArgumentException('Month is already hard-closed.');
        }

        $softClose = MonthSoftClose::query()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'year' => $year,
            'month' => $month,
            'closed_at' => now(),
        ]);

        $autoHardClose = null;
        $familyUserCount = $user->family->users()->count();
        if ($familyUserCount === 1) {
            $autoHardClose = $this->hardClose($user->family, $user, $year, $month);
        }

        return [
            'soft_close' => $softClose,
            'hard_close' => $autoHardClose,
        ];
    }

    /**
     * Remove a soft close record for a user in a given month.
     *
     * @throws InvalidArgumentException If no soft close exists or a hard close already exists
     */
    public function undoSoftClose(User $user, int $year, int $month): void
    {
        $softClose = MonthSoftClose::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $softClose) {
            throw new InvalidArgumentException('No soft close found for this user/month.');
        }

        $hardClose = MonthHardClose::query()
            ->where('family_id', $user->family_id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($hardClose) {
            throw new InvalidArgumentException('Cannot undo soft close: month is already hard-closed.');
        }

        $softClose->delete();
    }

    /**
     * Check if all users in a family have soft-closed a given month.
     */
    public function allMembersSoftClosed(Family $family, int $year, int $month): bool
    {
        $familyUserCount = $family->users()->count();
        $softCloseCount = MonthSoftClose::query()
            ->where('family_id', $family->id)
            ->where('year', $year)
            ->where('month', $month)
            ->count();

        return $softCloseCount === $familyUserCount;
    }

    /**
     * Check if a month is hard-closed for a family.
     */
    public function isHardClosed(Family $family, int $year, int $month): bool
    {
        return MonthHardClose::query()
            ->where('family_id', $family->id)
            ->where('year', $year)
            ->where('month', $month)
            ->exists();
    }

    /**
     * Get the status of a month for a family.
     *
     * @return array{
     *   soft_closes: Collection,
     *   hard_close: MonthHardClose|null,
     *   all_soft_closed: bool,
     *   family_user_count: int
     * }
     */
    public function getMonthStatus(Family $family, int $year, int $month): array
    {
        $softCloses = MonthSoftClose::query()
            ->where('family_id', $family->id)
            ->where('year', $year)
            ->where('month', $month)
            ->with('user')
            ->get();

        $hardClose = MonthHardClose::query()
            ->where('family_id', $family->id)
            ->where('year', $year)
            ->where('month', $month)
            ->with('closedBy')
            ->first();

        $familyUserCount = $family->users()->count();
        $allSoftClosed = $this->allMembersSoftClosed($family, $year, $month);

        return [
            'soft_closes' => $softCloses,
            'hard_close' => $hardClose,
            'all_soft_closed' => $allSoftClosed,
            'family_user_count' => $familyUserCount,
        ];
    }

    /**
     * Hard-close a month for a family.
     *
     * This processes all user closeout rules and confirms pending split debts.
     *
     * @throws InvalidArgumentException If not all members have soft-closed or month already hard-closed
     */
    public function hardClose(Family $family, User $closingUser, int $year, int $month): MonthHardClose
    {
        if (! $this->allMembersSoftClosed($family, $year, $month)) {
            throw new InvalidArgumentException('Not all family members have soft-closed this month.');
        }

        if ($this->isHardClosed($family, $year, $month)) {
            throw new InvalidArgumentException('Month is already hard-closed.');
        }

        return DB::transaction(function () use ($family, $closingUser, $year, $month) {
            foreach ($family->users as $user) {
                $this->processUserCloseoutRules($user, $year, $month);
            }

            $this->consolidatePendingSplitDebts($family, $year, $month);

            return MonthHardClose::query()->create([
                'family_id' => $family->id,
                'year' => $year,
                'month' => $month,
                'closed_at' => now(),
                'closed_by_user_id' => $closingUser->id,
            ]);
        });
    }

    /**
     * Process a user's active closeout rules for a given month.
     *
     * @private
     */
    private function processUserCloseoutRules(User $user, int $year, int $month): void
    {
        $grossIncome = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_borrow', false)
            ->where('is_debt_payment', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

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

            $grossRemaining -= $allocate;
            $grossAllocationsTotal += $allocate;

            $this->applyRuleAllocation($rule, $user, $year, $month, $allocate);

            if ($grossRemaining <= 0) {
                break;
            }
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
            ->whereHas('transaction', function ($q) use ($year, $month) {
                $q->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->where('type', 'expense');
            })
            ->sum('amount');

        $totalExpenses = $soloExpenses + $splitExpenses;

        $remainingPool = $grossIncome - $grossAllocationsTotal - $totalExpenses;

        if ($remainingPool <= 0) {
            return;
        }

        foreach ($remainingRules as $rule) {
            if ($rule->allocation_type === 'percentage') {
                $allocate = round($remainingPool * $rule->amount / 100, 2);
            } else {
                $allocate = min((float) $rule->amount, $remainingPool);
            }

            if ($allocate <= 0) {
                continue;
            }

            $remainingPool -= $allocate;

            $this->applyRuleAllocation($rule, $user, $year, $month, $allocate);

            if ($remainingPool <= 0) {
                break;
            }
        }
    }

    /**
     * Apply a rule allocation to the appropriate destination (fund, debt, or title).
     *
     * @private
     */
    private function applyRuleAllocation(FundRule $rule, User $user, int $year, int $month, float $amount): void
    {
        match ($rule->destination_type) {
            'fund' => $this->allocateToFund($rule, $user, $year, $month, $amount),
            'debt' => $this->allocateToDebt($rule, $user, $year, $month, $amount),
            'title' => $this->allocateToTitle($rule, $user, $year, $month, $amount),
        };
    }

    /**
     * Allocate funds to a fund.
     *
     * @private
     */
    private function allocateToFund(FundRule $rule, User $user, int $year, int $month, float $amount): void
    {
        $fund = Fund::query()->findOrFail($rule->destination_id);
        $fund->increment('balance', $amount);

        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'closeout_allocation',
            'amount' => $amount,
            'description' => "Closeout rule: {$rule->name} ({$year}-{$month})",
        ]);
    }

    /**
     * Allocate funds to pay down a debt.
     *
     * @private
     */
    private function allocateToDebt(FundRule $rule, User $user, int $year, int $month, float $amount): void
    {
        $debt = Debt::query()
            ->where('id', $rule->destination_id)
            ->where('debtor_id', $user->id)
            ->first();

        if ($debt && $debt->balance > 0) {
            $payAmount = min($amount, (float) $debt->balance);
            $debt->decrement('balance', $payAmount);

            Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'type' => 'expense',
                'amount' => $payAmount,
                'description' => "Closeout debt payment: {$rule->name} ({$year}-{$month})",
                'transaction_date' => Carbon::create($year, $month)->endOfMonth()->toDateString(),
                'is_debt_payment' => true,
            ]);
        }
    }

    /**
     * Allocate funds to a titled savings record.
     *
     * @private
     */
    private function allocateToTitle(FundRule $rule, User $user, int $year, int $month, float $amount): void
    {
        $titleSaving = CloseoutTitleSaving::query()->firstOrNew([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'year' => $year,
            'month' => $month,
            'title' => $rule->destination_title,
        ]);

        $titleSaving->amount = ($titleSaving->amount ?? 0) + $amount;
        $titleSaving->rule_id = $rule->id;
        $titleSaving->save();
    }

    /**
     * Consolidate pending split debts by netting amounts per person-pair and creating confirmed debts.
     *
     * @private
     */
    private function consolidatePendingSplitDebts(Family $family, int $year, int $month): void
    {
        $pendingDebts = Debt::query()
            ->where('family_id', $family->id)
            ->where('is_pending_closeout', true)
            ->whereHas('transaction', fn ($q) => $q
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month)
            )
            ->get();

        if ($pendingDebts->isEmpty()) {
            return;
        }

        $netAmounts = [];
        foreach ($pendingDebts as $debt) {
            $debtorId = $debt->debtor_id;
            $creditorId = $debt->creditor_id;
            $amount = (float) $debt->amount;

            [$lowId, $highId] = $debtorId < $creditorId
                ? [$debtorId, $creditorId]
                : [$creditorId, $debtorId];

            if (! isset($netAmounts[$lowId][$highId])) {
                $netAmounts[$lowId][$highId] = 0.0;
            }

            if ($debtorId === $lowId) {
                $netAmounts[$lowId][$highId] += $amount;
            } else {
                $netAmounts[$lowId][$highId] -= $amount;
            }
        }

        foreach ($netAmounts as $lowId => $higherIds) {
            foreach ($higherIds as $highId => $net) {
                if (abs($net) < 0.01) {
                    continue;
                }

                [$actualDebtorId, $actualCreditorId] = $net > 0
                    ? [$lowId, $highId]
                    : [$highId, $lowId];
                $netAmount = abs($net);

                $existingDebt = Debt::query()
                    ->where('family_id', $family->id)
                    ->where('debtor_id', $actualDebtorId)
                    ->where('creditor_id', $actualCreditorId)
                    ->where('is_pending_closeout', false)
                    ->whereNull('transaction_id')
                    ->first();

                if ($existingDebt) {
                    $existingDebt->increment('amount', $netAmount);
                    $existingDebt->increment('balance', $netAmount);
                } else {
                    Debt::query()->create([
                        'family_id' => $family->id,
                        'debtor_id' => $actualDebtorId,
                        'creditor_id' => $actualCreditorId,
                        'amount' => $netAmount,
                        'balance' => $netAmount,
                        'is_pending_closeout' => false,
                        'description' => 'Split settlements from '.$month.'/'.$year,
                    ]);
                }
            }
        }

        $pendingDebtIds = $pendingDebts->pluck('id');
        Debt::query()->whereIn('id', $pendingDebtIds)->delete();
    }
}
