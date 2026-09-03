<?php

namespace App\Services;

use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\MonthHardClose;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Closeout\CloseoutEngineResolver;
use App\Services\Closeout\CloseoutMode;
use App\Services\Closeout\CloseoutTotals;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MonthCloseoutService
{
    public function __construct(
        private DebtService $debtService,
        private CloseoutEngineResolver $closeoutEngineResolver,
        private CloseoutTotals $closeoutTotals,
    ) {}

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
     * expense rows, borrow transactions, and expenses repaid via expense-repayment linking (`is_repaid`)
     * so hard-close math stays stable.
     * Excludes advances marked exclude-from-remaining (`exclude_from_expense_basis = true`) in **classic** closeout only; family pooled ignores that flag. Their deduction from fund balances
     * is handled by applyFundAdvances() at closeout.
     */
    public function expenseTotalTowardRemainingBasis(User $user, int $year, int $month): float
    {
        return $this->closeoutTotals->expenseTotalTowardRemainingBasis($user, $year, $month);
    }

    /**
     * Month advance-tagged expense totals per fund for the user (same basis as rule-preview advance netting).
     *
     * @return array<int, float>
     */
    public function fundAdvanceOutstandingByFundForUserMonth(User $user, int $year, int $month): array
    {
        return $this->closeoutTotals->fundAdvanceOutstandingByFundForUserMonth($user, $year, $month);
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
            $family->loadMissing('users');
            $engine = $this->closeoutEngineResolver->for($family);
            $settingsSnapshot = $this->closeoutEngineResolver->settingsSnapshot($family);
            $resultsSnapshot = $engine->preview($family, $year, $month);
            $engine->apply($family, $closingUser, $year, $month);

            foreach ($family->users as $user) {
                $this->applyFundAdvances($user, sprintf('%04d-%02d', $year, $month), $year, $month);
            }

            $this->consolidatePendingSplitDebts($family, $year, $month);
            $this->applyMonthlyDebtInterest($family, $year, $month);

            return MonthHardClose::query()->create([
                'family_id' => $family->id,
                'year' => $year,
                'month' => $month,
                'closed_at' => now(),
                'closed_by_user_id' => $closingUser->id,
                'closeout_mode' => CloseoutMode::normalize($family->closeout_mode),
                'settings_snapshot' => $settingsSnapshot,
                'results_snapshot' => $resultsSnapshot,
            ]);
        });
    }

    /**
     * Fully revert a hard close for a family, restoring data to pre-close state.
     *
     * @throws InvalidArgumentException If no hard close exists for this month
     */
    public function undoHardClose(Family $family, int $year, int $month): void
    {
        DB::transaction(function () use ($family, $year, $month): void {
            $hardClose = MonthHardClose::query()
                ->where('family_id', $family->id)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if (! $hardClose) {
                throw new InvalidArgumentException('No hard close found for this month.');
            }

            $familyUserIds = $family->users()->pluck('id')->all();
            $closeoutMonthTag = sprintf('%04d-%02d', $year, $month);

            $closeoutDebtPaymentTransactions = Transaction::query()
                ->whereIn('user_id', $familyUserIds)
                ->where('is_closeout_initiated', true)
                ->where('is_debt_payment', true)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month)
                ->get();

            foreach ($closeoutDebtPaymentTransactions as $transaction) {
                if (! $transaction->debt_id) {
                    continue;
                }

                $debt = Debt::query()
                    ->where('id', $transaction->debt_id)
                    ->lockForUpdate()
                    ->first();

                if (! $debt) {
                    continue;
                }

                $debt->increment('balance', (float) $transaction->amount);
            }

            $closeoutAllocationMovements = FundMovement::query()
                ->whereIn('user_id', $familyUserIds)
                ->where('type', 'closeout_allocation')
                ->where('description', 'like', '%('.$closeoutMonthTag.')%')
                ->get();

            foreach ($closeoutAllocationMovements as $movement) {
                $fund = Fund::query()
                    ->where('id', $movement->fund_id)
                    ->lockForUpdate()
                    ->first();

                if (! $fund) {
                    continue;
                }

                $fund->decrement('balance', (float) $movement->amount);
            }

            $advanceSettlementMovements = FundMovement::query()
                ->whereIn('user_id', $familyUserIds)
                ->where('type', 'advance_settlement')
                ->where('description', 'like', '%('.$closeoutMonthTag.'%')
                ->get();

            foreach ($advanceSettlementMovements as $movement) {
                $fund = Fund::query()
                    ->where('id', $movement->fund_id)
                    ->lockForUpdate()
                    ->first();

                if (! $fund) {
                    continue;
                }

                $fund->increment('balance', (float) $movement->amount);
            }

            FundMovement::query()
                ->whereIn('id', $closeoutAllocationMovements->pluck('id'))
                ->delete();

            FundMovement::query()
                ->whereIn('id', $advanceSettlementMovements->pluck('id'))
                ->delete();

            Transaction::query()
                ->whereIn('user_id', $familyUserIds)
                ->where('is_closeout_initiated', true)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month)
                ->delete();

            $titleSavings = CloseoutTitleSaving::query()
                ->where('family_id', $family->id)
                ->where('year', $year)
                ->where('month', $month)
                ->get();

            foreach ($titleSavings as $titleSaving) {
                if (! $titleSaving->completion_transaction_id) {
                    continue;
                }

                Transaction::query()
                    ->where('id', $titleSaving->completion_transaction_id)
                    ->delete();
            }

            CloseoutTitleSaving::query()
                ->whereIn('id', $titleSavings->pluck('id'))
                ->delete();

            $confirmedDebts = Debt::query()
                ->where('family_id', $family->id)
                ->where('is_pending_closeout', false)
                ->whereNotNull('contributions')
                ->lockForUpdate()
                ->get();

            foreach ($confirmedDebts as $debt) {
                $contributions = $debt->contributions ?? [];
                $monthContributions = array_filter($contributions, function ($contribution) use ($year, $month): bool {
                    return (int) ($contribution['month'] ?? 0) === $month
                        && (int) ($contribution['year'] ?? 0) === $year;
                });

                if (empty($monthContributions)) {
                    continue;
                }

                $monthAmount = array_sum(array_map(
                    static fn (array $contribution): float => (float) ($contribution['amount'] ?? 0),
                    $monthContributions
                ));

                $remainingContributions = array_values(array_filter(
                    $contributions,
                    function ($contribution) use ($year, $month): bool {
                        return ! (
                            (int) ($contribution['month'] ?? 0) === $month
                            && (int) ($contribution['year'] ?? 0) === $year
                        );
                    }
                ));

                $allMonthContributionsCreatedDebt = count($monthContributions) > 0
                    && collect($monthContributions)->every(
                        fn (array $contribution): bool => (bool) ($contribution['created_by_closeout_debt'] ?? false)
                    );

                if (empty($remainingContributions) && $allMonthContributionsCreatedDebt) {
                    $debt->delete();

                    continue;
                }

                $debt->update([
                    'amount' => max(0, (float) $debt->amount - $monthAmount),
                    'balance' => max(0, (float) $debt->balance - $monthAmount),
                    'contributions' => $remainingContributions,
                ]);
            }

            $splitTransactions = Transaction::query()
                ->where('family_id', $family->id)
                ->where('is_split', true)
                ->where('is_closeout_initiated', false)
                ->whereYear('transaction_date', $year)
                ->whereMonth('transaction_date', $month)
                ->with('splits')
                ->get();

            foreach ($splitTransactions as $transaction) {
                foreach ($transaction->splits as $split) {
                    if ($split->user_id === $transaction->user_id) {
                        continue;
                    }

                    $exists = Debt::query()
                        ->where('transaction_id', $transaction->id)
                        ->where('debtor_id', $split->user_id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    Debt::query()->create([
                        'family_id' => $transaction->family_id,
                        'debtor_id' => $split->user_id,
                        'creditor_id' => $transaction->user_id,
                        'transaction_id' => $transaction->id,
                        'amount' => $split->amount,
                        'balance' => $split->amount,
                        'description' => "Split from transaction #{$transaction->id}",
                        'is_pending_closeout' => true,
                    ]);
                }
            }

            $interestDebts = Debt::query()
                ->where('family_id', $family->id)
                ->where('interest_enabled', true)
                ->whereNotNull('interest_accruals')
                ->lockForUpdate()
                ->get();

            foreach ($interestDebts as $debt) {
                $interestAccruals = $debt->interest_accruals ?? [];
                $monthAccruals = array_filter($interestAccruals, function ($accrual) use ($year, $month): bool {
                    return (int) ($accrual['year'] ?? 0) === $year
                        && (int) ($accrual['month'] ?? 0) === $month;
                });

                if (empty($monthAccruals)) {
                    continue;
                }

                $interestToReverse = array_sum(array_map(
                    static fn (array $accrual): float => (float) ($accrual['amount'] ?? 0),
                    $monthAccruals
                ));

                $remainingAccruals = array_values(array_filter(
                    $interestAccruals,
                    function ($accrual) use ($year, $month): bool {
                        return ! (
                            (int) ($accrual['year'] ?? 0) === $year
                            && (int) ($accrual['month'] ?? 0) === $month
                        );
                    }
                ));

                $newLastAppliedAt = null;
                if (! empty($remainingAccruals)) {
                    $lastAccrual = end($remainingAccruals);
                    $newLastAppliedAt = $lastAccrual['applied_at'] ?? null;
                }

                $debt->update([
                    'balance' => max(0, (float) $debt->balance - $interestToReverse),
                    'interest_accruals' => empty($remainingAccruals) ? null : $remainingAccruals,
                    'interest_last_applied_at' => $newLastAppliedAt,
                ]);
            }

            MonthSoftClose::query()
                ->where('family_id', $family->id)
                ->where('year', $year)
                ->where('month', $month)
                ->delete();

            MonthHardClose::query()
                ->where('family_id', $family->id)
                ->where('year', $year)
                ->where('month', $month)
                ->delete();
        });
    }

    /**
     * Deduct advance-against-fund expenses from fund balances at closeout.
     */
    private function applyFundAdvances(User $user, string $closeoutMonthTag, int $year, int $month): void
    {
        $advances = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereNotNull('advance_fund_id')
            ->where('is_repaid', false)
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

                $this->debtService->applyInterFamilyPairNet(
                    (int) $family->id,
                    (int) $actualDebtorId,
                    (int) $actualCreditorId,
                    $netAmount,
                    'Split settlements from '.$month.'/'.$year,
                    ['month' => $month, 'year' => $year],
                );
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
