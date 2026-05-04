<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FundService
{
    /**
     * Process income transaction by allocating funds according to active rules.
     *
     * Loads all active fund rules ordered by priority and allocates the income amount
     * based on allocation type (percentage or fixed) and allocation base (gross, net, or remaining).
     *
     * @param  Transaction  $income  The income transaction to process
     * @param  User  $user  The user associated with the income
     */
    public function processIncome(Transaction $income, User $user): void
    {
        $rules = FundRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('order')
            ->with('fund')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $gross = $income->amount;
        $net = $gross;
        $remaining = $gross;

        foreach ($rules as $rule) {
            $base = match ($rule->allocation_base) {
                'gross_income' => $gross,
                'net_income' => $net,
                'remaining' => $remaining,
                default => $gross,
            };

            if ($rule->allocation_type === 'percentage') {
                $allocate = round($base * $rule->amount / 100, 2);
            } else {
                $allocate = min((float) $rule->amount, $remaining);
            }

            if ($allocate <= 0) {
                continue;
            }

            $remaining -= $allocate;

            $rule->fund->increment('balance', $allocate);

            FundMovement::query()->create([
                'fund_id' => $rule->fund_id,
                'user_id' => $user->id,
                'type' => 'allocation',
                'amount' => $allocate,
                'transaction_id' => $income->id,
            ]);

            if ($remaining <= 0) {
                break;
            }
        }
    }

    /**
     * Create a borrow transaction from a fund.
     *
     * Decrements the fund balance, creates a borrow fund movement, and creates a corresponding debt record.
     * All operations are wrapped in a database transaction for atomicity.
     *
     * @param  Fund  $fund  The fund to borrow from
     * @param  float  $amount  The amount to borrow
     * @param  string  $description  Description for the transaction
     * @param  User  $user  The user borrowing from the fund
     * @return Transaction The created income transaction
     *
     * @throws InvalidArgumentException When fund balance is insufficient
     */
    public function borrowFromFund(Fund $fund, float $amount, string $description, User $user): Transaction
    {
        if ($fund->balance < $amount) {
            throw new InvalidArgumentException('Insufficient fund balance');
        }

        return DB::transaction(function () use ($fund, $amount, $description, $user) {
            $transaction = Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'type' => 'income',
                'is_borrow' => true,
                'amount' => $amount,
                'description' => $description,
                'transaction_date' => today(),
            ]);

            $fund->decrement('balance', $amount);

            FundMovement::query()->create([
                'fund_id' => $fund->id,
                'user_id' => $user->id,
                'type' => 'borrow',
                'amount' => $amount,
                'transaction_id' => $transaction->id,
            ]);

            Debt::query()->create([
                'debtor_id' => $user->id,
                'fund_id' => $fund->id,
                'family_id' => $user->family_id,
                'amount' => $amount,
                'balance' => $amount,
                'description' => "Borrow from fund: {$fund->name}",
                'transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * Repay a fund debt.
     *
     * Increments the fund balance, creates a repayment fund movement, and decrements the debt balance.
     * All operations are wrapped in a database transaction for atomicity.
     *
     * @param  Debt  $debt  The debt to repay
     * @param  float  $amount  The amount to repay
     * @param  User  $user  The user repaying the debt
     *
     * @throws InvalidArgumentException When debt has no fund association, user is not the debtor, or amount is invalid
     */
    public function repayFund(Debt $debt, float $amount, User $user): void
    {
        if ($debt->fund_id === null) {
            throw new InvalidArgumentException('Debt must be associated with a fund');
        }

        if ($user->id !== $debt->debtor_id) {
            throw new InvalidArgumentException('User must be the debtor');
        }

        if ($amount <= 0 || $amount > $debt->balance) {
            throw new InvalidArgumentException('Repayment amount must be greater than 0 and not exceed debt balance');
        }

        DB::transaction(function () use ($debt, $amount, $user) {
            $transaction = Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'type' => 'expense',
                'is_debt_payment' => true,
                'amount' => $amount,
                'description' => 'Fund repayment',
                'transaction_date' => today(),
            ]);

            $fund = Fund::query()->findOrFail($debt->fund_id);
            $fund->increment('balance', $amount);

            FundMovement::query()->create([
                'fund_id' => $debt->fund_id,
                'user_id' => $user->id,
                'type' => 'repayment',
                'amount' => $amount,
                'transaction_id' => $transaction->id,
            ]);

            $debt->decrement('balance', $amount);
        });
    }
}
