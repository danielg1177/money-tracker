<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;

class CloseoutTotals
{
    public function expenseTotalTowardRemainingBasis(User $user, int $year, int $month, bool $applyExpenseBasisExclusion = true): float
    {
        if (! $user->family_id) {
            return 0.0;
        }

        $solo = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_closeout_initiated', false)
            ->where('is_borrow', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month);

        if ($applyExpenseBasisExclusion) {
            $solo->where('exclude_from_expense_basis', false);
        }

        $soloTotal = (float) $solo->sum('amount');

        $split = (float) TransactionSplit::query()
            ->where('user_id', $user->id)
            ->whereHas('transaction', function ($q) use ($user, $year, $month): void {
                $q->where('family_id', $user->family_id)
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->where('type', 'expense')
                    ->where('is_closeout_initiated', false)
                    ->where('is_borrow', false)
                    ->whereNotRepaidExpense();
            })
            ->sum('amount');

        return $soloTotal + $split;
    }

    /**
     * @return array<int, float>
     */
    public function fundAdvanceOutstandingByFundForUserMonth(User $user, int $year, int $month): array
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->whereNotNull('advance_fund_id')
            ->where('is_repaid', false)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->selectRaw('advance_fund_id, SUM(amount) as total_advanced')
            ->groupBy('advance_fund_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->advance_fund_id => (float) $row->total_advanced])
            ->all();
    }

    public function interMemberDebtPaymentExpenses(User $user, int $year, int $month, bool $isNecessity): float
    {
        if (! $user->family_id) {
            return 0.0;
        }

        $solo = Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_split', false)
            ->where('is_debt_payment', true)
            ->where('is_closeout_initiated', false)
            ->where('is_borrow', false)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->whereHas('debt', fn ($q) => $q->whereNotNull('creditor_id'))
            ->where('is_necessity', $isNecessity);

        $split = (float) TransactionSplit::query()
            ->where('user_id', $user->id)
            ->whereHas('transaction', function ($q) use ($user, $year, $month, $isNecessity): void {
                $q->where('family_id', $user->family_id)
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->where('type', 'expense')
                    ->where('is_debt_payment', true)
                    ->where('is_closeout_initiated', false)
                    ->where('is_borrow', false)
                    ->whereNotRepaidExpense()
                    ->whereHas('debt', fn ($dq) => $dq->whereNotNull('creditor_id'))
                    ->where('is_necessity', $isNecessity);
            })
            ->sum('amount');

        return (float) $solo->sum('amount') + $split;
    }

    public function userCharityExpenses(User $user, int $year, int $month, bool $isNecessity): float
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
            ->where('is_necessity', $isNecessity)
            ->whereNotRepaidExpense()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');

        $split = (float) TransactionSplit::query()
            ->where('user_id', $user->id)
            ->whereHas('transaction', function ($q) use ($user, $year, $month, $isNecessity): void {
                $q->where('family_id', $user->family_id)
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->where('type', 'expense')
                    ->where('is_closeout_initiated', false)
                    ->where('is_borrow', false)
                    ->where('is_necessity', $isNecessity)
                    ->whereNotRepaidExpense();
            })
            ->sum('amount');

        return $solo + $split;
    }

    public function familyNecessaryExpenses(Family $family, int $year, int $month): float
    {
        $total = 0.0;

        foreach ($family->users as $user) {
            $total += $this->userCharityExpenses($user, $year, $month, true)
                - $this->interMemberDebtPaymentExpenses($user, $year, $month, true);
        }

        return round($total, 2);
    }

    public function familyNonNecessityExpenses(Family $family, int $year, int $month): float
    {
        $total = 0.0;

        foreach ($family->users as $user) {
            $total += $this->userCharityExpenses($user, $year, $month, false)
                - $this->interMemberDebtPaymentExpenses($user, $year, $month, false);
        }

        return round($total, 2);
    }

    public function familyAllExpenses(Family $family, int $year, int $month): float
    {
        return round(
            $this->familyNecessaryExpenses($family, $year, $month)
            + $this->familyNonNecessityExpenses($family, $year, $month),
            2
        );
    }

    public function earnedIncomeForPreview(User $user, int $year, int $month): float
    {
        return (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_borrow', false)
            ->where('is_debt_payment', false)
            ->whereNotRepaymentIncome()
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->sum('amount');
    }

    public function familyEarnedIncome(Family $family, int $year, int $month): float
    {
        $total = 0.0;

        foreach ($family->users as $user) {
            $total += $this->earnedIncomeForPreview($user, $year, $month);
        }

        return round($total, 2);
    }

    public function splitSpend(User $user, int $year, int $month): float
    {
        if (! $user->family_id) {
            return 0.0;
        }

        return (float) TransactionSplit::query()
            ->where('user_id', $user->id)
            ->whereHas('transaction', function ($q) use ($user, $year, $month): void {
                $q->where('family_id', $user->family_id)
                    ->whereYear('transaction_date', $year)
                    ->whereMonth('transaction_date', $month)
                    ->where('type', 'expense')
                    ->where('is_split', true)
                    ->where('is_closeout_initiated', false)
                    ->whereNotRepaidExpense();
            })
            ->sum('amount');
    }
}
