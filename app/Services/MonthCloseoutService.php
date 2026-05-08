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
     * Sum of the viewer's expenses that reduce remaining-after-expenses during closeout and in month-summary rule preview.
     *
     * Includes tracked debt repayments (solo payer amount and split shares). Excludes closeout-generated
     * expense rows and borrow transactions so hard-close math stays stable.
     * Excludes non-necessity advance transactions (is_non_necessity = true); their deduction from fund balances
     * is handled by applyFundAdvances() at closeout.
     */
    public function expenseTotalTowardRemainingBasis(User $user, int $year, int $month): float
    {
        return $this->calculateExpenseTotalTowardRemainingBasis($user, $year, $month);
    }

    /**
     * Month advance-tagged expense totals per fund for the user (same basis as rule-preview advance netting).
     *
     * @return array<int, float>
     */
    public function fundAdvanceOutstandingByFundForUserMonth(User $user, int $year, int $month): array
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereNotNull('advance_fund_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->selectRaw('advance_fund_id, SUM(amount) as total_advanced')
            ->groupBy('advance_fund_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->advance_fund_id => (float) $row->total_advanced])
            ->all();
    }

    /**
     * @see expenseTotalTowardRemainingBasis()
     */
    private function calculateExpenseTotalTowardRemainingBasis(User $user, int $year, int $month): float
    {
        if (! $user->family_id) {
            return 0.0;
        }

        $solo = (float) Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_closeout_initiated', false)
            ->where('is_borrow', false)
            ->where('is_non_necessity', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $split = (float) TransactionSplit::query()
            ->where('user_id', $user->id)
            ->whereHas('transaction', function ($q) use ($user, $year, $month): void {
                $q->where('family_id', $user->family_id)
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->where('type', 'expense')
                    ->where('is_closeout_initiated', false)
                    ->where('is_borrow', false);
            })
            ->sum('amount');

        return $solo + $split;
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
            $this->applyMonthlyDebtInterest($family, $year, $month);

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
        $closeoutMonthTag = sprintf('%04d-%02d', $year, $month);

        $grossIncome = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_borrow', false)
            ->where('is_debt_payment', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        if ($grossIncome > 0) {
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

            $fundAdvanceRemaining = $this->fundAdvanceOutstandingByFundForUserMonth($user, $year, $month);

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

                $actualAllocated = $this->applyRuleAllocation($rule, $user, $year, $month, $allocate);
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

            $totalExpenses = $this->calculateExpenseTotalTowardRemainingBasis($user, $year, $month);

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

                    $actualAllocated = $this->applyRuleAllocation($rule, $user, $year, $month, $allocate);
                    $remainingAvailablePool -= $actualAllocated;

                    if ($remainingAvailablePool <= 0) {
                        break;
                    }
                }
            }
        }

        $this->applyFundAdvances($user, $closeoutMonthTag, $year, $month);
    }

    /**
     * Apply a rule allocation to the appropriate destination (fund, debt, or title).
     *
     * @private
     */
    private function applyRuleAllocation(FundRule $rule, User $user, int $year, int $month, float $amount): float
    {
        return match ($rule->destination_type) {
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
    private function allocateToFund(FundRule $rule, User $user, int $year, int $month, float $amount): float
    {
        $fund = Fund::query()->findOrFail($rule->destination_id);
        $fund->increment('balance', $amount);

        Transaction::query()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $rule->closeout_expense_category_id,
            'type' => 'expense',
            'amount' => $amount,
            'description' => "Closeout transfer to fund: {$fund->name}",
            'transaction_date' => $this->resolveCloseoutTransactionDate($year, $month),
            'is_debt_payment' => false,
            'is_closeout_initiated' => true,
            'is_split' => false,
            'split_data' => null,
        ]);

        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'closeout_allocation',
            'amount' => $amount,
            'description' => sprintf('Closeout rule: %s (%04d-%02d)', $rule->name, $year, $month),
        ]);

        return $amount;
    }

    /**
     * Allocate funds to pay down a debt.
     *
     * Allow any family member to contribute to family debts through closeout rules.
     *
     * @private
     */
    private function allocateToDebt(FundRule $rule, User $user, int $year, int $month, float $amount): float
    {
        $debt = Debt::query()
            ->where('id', $rule->destination_id)
            ->where('family_id', $user->family_id)
            ->first();

        if ($debt && $debt->balance > 0) {
            $payAmount = min($amount, (float) $debt->balance);
            $debt->decrement('balance', $payAmount);

            $debtLabel = $debt->creditor_name ?? $debt->creditor?->name ?? 'Unknown';

            Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'category_id' => $rule->closeout_expense_category_id,
                'type' => 'expense',
                'amount' => $payAmount,
                'description' => "Debt Payment: {$debtLabel}",
                'transaction_date' => $this->resolveCloseoutTransactionDate($year, $month),
                'is_debt_payment' => true,
                'debt_id' => $debt->id,
                'paid_by_user_id' => $user->id,
                'is_closeout_initiated' => true,
            ]);

            return $payAmount;
        }

        return 0;
    }

    /**
     * Resolve transaction date for closeout-generated entries.
     */
    private function resolveCloseoutTransactionDate(int $year, int $month): string
    {
        $now = now();

        if ((int) $now->year === $year && (int) $now->month === $month) {
            return $now->toDateString();
        }

        return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
    }

    /**
     * Allocate funds to a titled savings record.
     *
     * @private
     */
    private function allocateToTitle(FundRule $rule, User $user, int $year, int $month, float $amount): float
    {
        $titleSaving = CloseoutTitleSaving::query()->firstOrNew([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'year' => $year,
            'month' => $month,
            'title' => $rule->destination_title,
        ]);

        $titleSaving->amount = ($titleSaving->amount ?? 0) + $amount;

        if (! $titleSaving->exists) {
            $titleSaving->rule_id = $rule->id;
        }

        $titleSaving->save();

        return $amount;
    }

    /**
     * Deduct advance-against-fund expenses from fund balances at closeout.
     *
     * @private
     */
    private function applyFundAdvances(User $user, string $closeoutMonthTag, int $year, int $month): void
    {
        $advances = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereNotNull('advance_fund_id')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->selectRaw('advance_fund_id, SUM(amount) as total_advanced')
            ->groupBy('advance_fund_id')
            ->get();

        foreach ($advances as $advance) {
            $fund = Fund::query()->find($advance->advance_fund_id);
            if (! $fund) {
                continue;
            }

            $total = (float) $advance->total_advanced;
            $fund->decrement('balance', $total);

            FundMovement::query()->create([
                'fund_id' => $fund->id,
                'user_id' => $user->id,
                'type' => 'advance_settlement',
                'amount' => $total,
                'description' => "Advance settlement ({$closeoutMonthTag})",
            ]);
        }
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
            ->where(function ($q) use ($year, $month): void {
                $q->whereNull('transaction_id')
                    ->orWhereHas('transaction', fn ($q) => $q
                        ->whereYear('transaction_date', $year)
                        ->whereMonth('transaction_date', $month)
                    );
            })
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

                $contribution = ['month' => $month, 'year' => $year, 'amount' => $netAmount];
                if ($existingDebt) {
                    $existingDebt->amount = (float) $existingDebt->amount + $netAmount;
                    $existingDebt->balance = (float) $existingDebt->balance + $netAmount;
                    $existingDebt->contributions = array_merge($existingDebt->contributions ?? [], [$contribution]);
                    $existingDebt->save();
                } else {
                    Debt::query()->create([
                        'family_id' => $family->id,
                        'debtor_id' => $actualDebtorId,
                        'creditor_id' => $actualCreditorId,
                        'amount' => $netAmount,
                        'balance' => $netAmount,
                        'is_pending_closeout' => false,
                        'description' => 'Split settlements from '.$month.'/'.$year,
                        'contributions' => [$contribution],
                    ]);
                }
            }
        }

        $pendingDebtIds = $pendingDebts->pluck('id');
        Debt::query()->whereIn('id', $pendingDebtIds)->delete();
    }

    /**
     * Apply one month of interest to eligible family debts at closeout month-end.
     *
     * Interest is accrued through the closed month's last day regardless of when
     * users soft-close or hard-close in real time.
     *
     * @private
     */
    private function applyMonthlyDebtInterest(Family $family, int $year, int $month): void
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        $monthEndString = $monthEnd->toDateString();

        Debt::query()
            ->where('family_id', $family->id)
            ->where('is_pending_closeout', false)
            ->where('interest_enabled', true)
            ->where('balance', '>', 0)
            ->whereNotNull('interest_rate')
            ->where(function ($query) use ($monthEndString): void {
                $query->whereNull('interest_last_applied_at')
                    ->orWhere('interest_last_applied_at', '<', $monthEndString);
            })
            ->lockForUpdate()
            ->get()
            ->each(function (Debt $debt) use ($year, $month, $monthStart, $monthEnd, $monthEndString): void {
                $periodStart = $monthStart->copy();
                $loanReceivedDate = $debt->loan_received_date
                    ? Carbon::parse($debt->loan_received_date)->startOfDay()
                    : Carbon::parse($debt->created_at)->startOfDay();

                if ($loanReceivedDate->greaterThan($periodStart)) {
                    $periodStart = $loanReceivedDate->copy();
                }

                if ($debt->interest_last_applied_at) {
                    $nextInterestDate = Carbon::parse($debt->interest_last_applied_at)->addDay()->startOfDay();
                    if ($nextInterestDate->greaterThan($periodStart)) {
                        $periodStart = $nextInterestDate->copy();
                    }
                }

                if ($periodStart->greaterThan($monthEnd)) {
                    $debt->update([
                        'interest_last_applied_at' => $monthEndString,
                    ]);

                    return;
                }

                $paymentsByDate = Transaction::query()
                    ->where('debt_id', $debt->id)
                    ->where('type', 'expense')
                    ->where('is_debt_payment', true)
                    ->whereDate('transaction_date', '>=', $periodStart->toDateString())
                    ->whereDate('transaction_date', '<=', $monthEnd->toDateString())
                    ->selectRaw('DATE(transaction_date) as payment_date, SUM(amount) as payment_total')
                    ->groupByRaw('DATE(transaction_date)')
                    ->orderBy('payment_date')
                    ->get();

                $totalPayments = round((float) $paymentsByDate->sum('payment_total'), 2);
                $runningBalance = round((float) $debt->balance + $totalPayments, 2);
                $dailyRate = ((float) $debt->interest_rate / 100) / 365;
                $interestAmount = 0.0;
                $cursorDate = $periodStart->copy();

                foreach ($paymentsByDate as $payment) {
                    $paymentDate = Carbon::parse($payment->payment_date)->startOfDay();
                    if ($paymentDate->lt($cursorDate)) {
                        continue;
                    }

                    $days = $cursorDate->diffInDays($paymentDate);
                    if ($days > 0 && $runningBalance > 0) {
                        $interestAmount += $runningBalance * $dailyRate * $days;
                    }

                    $runningBalance = round(max(0, $runningBalance - (float) $payment->payment_total), 2);
                    $cursorDate = $paymentDate->copy();
                }

                $endExclusive = $monthEnd->copy()->addDay();
                $remainingDays = $cursorDate->diffInDays($endExclusive);
                if ($remainingDays > 0 && $runningBalance > 0) {
                    $interestAmount += $runningBalance * $dailyRate * $remainingDays;
                }

                $interestAmount = round($interestAmount, 2);

                if ($interestAmount <= 0) {
                    $debt->update([
                        'interest_last_applied_at' => $monthEndString,
                    ]);

                    return;
                }

                $nextInterestAccruals = array_merge($debt->interest_accruals ?? [], [[
                    'year' => $year,
                    'month' => $month,
                    'amount' => $interestAmount,
                    'applied_at' => $monthEndString,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $monthEndString,
                ]]);

                $debt->update([
                    'balance' => round((float) $debt->balance + $interestAmount, 2),
                    'interest_last_applied_at' => $monthEndString,
                    'interest_accruals' => $nextInterestAccruals,
                ]);
            });
    }
}
