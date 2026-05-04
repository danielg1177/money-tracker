<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DebtService
{
    /**
     * Pay a debt by creating corresponding transactions and updating the debt balance.
     *
     * @param  Debt  $debt  The debt record to pay
     * @param  float  $paymentAmount  The amount to pay (must be > 0 and <= balance)
     * @param  string  $description  Optional description for the transactions
     * @param  User  $payer  The user making the payment (must be the debtor)
     *
     * @throws InvalidArgumentException If payer is not the debtor or payment amount is invalid
     */
    public function payDebt(Debt $debt, float $paymentAmount, string $description, User $payer): void
    {
        if ($debt->is_pending_closeout) {
            throw new InvalidArgumentException('Cannot pay a pending split debt. It will be settled during month closeout.');
        }

        if ($debt->is_family_debt) {
            if ($payer->family_id !== $debt->family_id) {
                throw new InvalidArgumentException('Payer must be a family member.');
            }
        } else {
            if ($payer->id !== $debt->debtor_id) {
                throw new InvalidArgumentException('Payer must be the debtor of this debt.');
            }
        }

        if ($paymentAmount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than 0.');
        }

        if ($paymentAmount > $debt->balance) {
            throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
        }

        DB::transaction(function () use ($debt, $paymentAmount, $description, $payer): void {
            $transactionDate = Carbon::today()->toDateString();

            Transaction::create([
                'family_id' => $payer->family_id,
                'user_id' => $payer->id,
                'type' => 'expense',
                'amount' => $paymentAmount,
                'description' => $description ?: 'Debt payment',
                'transaction_date' => $transactionDate,
                'is_debt_payment' => true,
            ]);

            if ($debt->creditor_id !== null) {
                Transaction::create([
                    'family_id' => $payer->family_id,
                    'user_id' => $debt->creditor_id,
                    'type' => 'income',
                    'amount' => $paymentAmount,
                    'description' => $description ?: 'Debt received',
                    'transaction_date' => $transactionDate,
                    'is_debt_payment' => true,
                ]);
            }

            $debt->balance -= $paymentAmount;
            $debt->save();
        });
    }
}
