<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Transaction;
use App\Models\TransactionSplit;
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
     * @param  bool  $isCloseoutInitiated  Whether the payment was initiated from a month closeout
     * @param  int|null  $splitWithUserId  User ID to split payment with (optional)
     * @param  float|null  $splitPercentage  Split percentage for the other user (optional)
     *
     * @throws InvalidArgumentException If payer is not the debtor or payment amount is invalid
     */
    public function payDebt(
        Debt $debt,
        float $paymentAmount,
        string $description,
        User $payer,
        bool $isCloseoutInitiated = false,
        ?int $splitWithUserId = null,
        ?float $splitPercentage = null,
    ): void {
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

        DB::transaction(function () use ($debt, $paymentAmount, $description, $payer, $isCloseoutInitiated, $splitWithUserId, $splitPercentage): void {
            $transactionDate = Carbon::today()->toDateString();
            $hasSplit = $splitWithUserId !== null && $splitPercentage !== null && $splitPercentage > 0;

            $payerTransaction = Transaction::create([
                'family_id' => $payer->family_id,
                'user_id' => $payer->id,
                'type' => 'expense',
                'amount' => $paymentAmount,
                'description' => $description ?: 'Debt payment',
                'transaction_date' => $transactionDate,
                'is_debt_payment' => true,
                'debt_id' => $debt->id,
                'paid_by_user_id' => $payer->id,
                'is_closeout_initiated' => $isCloseoutInitiated,
                'is_split' => $hasSplit,
                'split_data' => $hasSplit ? json_encode([
                    ['user_id' => $payer->id, 'share_percentage' => 100 - $splitPercentage],
                    ['user_id' => $splitWithUserId, 'share_percentage' => $splitPercentage],
                ]) : null,
            ]);

            if ($hasSplit) {
                $payerShare = round($paymentAmount * (100 - $splitPercentage) / 100, 2);
                $splitShare = $paymentAmount - $payerShare;

                TransactionSplit::create([
                    'transaction_id' => $payerTransaction->id,
                    'user_id' => $payer->id,
                    'share_percentage' => 100 - $splitPercentage,
                    'amount' => $payerShare,
                ]);

                TransactionSplit::create([
                    'transaction_id' => $payerTransaction->id,
                    'user_id' => $splitWithUserId,
                    'share_percentage' => $splitPercentage,
                    'amount' => $splitShare,
                ]);

                Debt::create([
                    'family_id' => $payer->family_id,
                    'debtor_id' => $splitWithUserId,
                    'creditor_id' => $payer->id,
                    'transaction_id' => $payerTransaction->id,
                    'amount' => $splitShare,
                    'balance' => $splitShare,
                    'description' => 'Split from debt payment: '.($description ?: 'Debt payment'),
                    'is_pending_closeout' => true,
                ]);
            }

            $creditorIncome = null;
            if ($debt->creditor_id !== null) {
                $creditorIncome = Transaction::create([
                    'family_id' => $payer->family_id,
                    'user_id' => $debt->creditor_id,
                    'type' => 'income',
                    'amount' => $paymentAmount,
                    'description' => $description ?: 'Debt received',
                    'transaction_date' => $transactionDate,
                    'is_debt_payment' => true,
                    'debt_id' => $debt->id,
                    'paid_by_user_id' => $payer->id,
                    'is_closeout_initiated' => $isCloseoutInitiated,
                ]);
            }

            $debt->balance -= $paymentAmount;
            $debt->save();

            if (! $hasSplit && $creditorIncome) {
                $payerTransaction->forceFill(['mirror_transaction_id' => $creditorIncome->id])->save();
                $creditorIncome->forceFill(['mirror_transaction_id' => $payerTransaction->id])->save();
            }
        });
    }
}
