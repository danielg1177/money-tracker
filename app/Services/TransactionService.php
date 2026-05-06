<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionService
{
    /**
     * Creates a transaction with optional split and debt records.
     *
     * When a transaction is split, TransactionSplit records are created for each split party,
     * and Debt records are created for each split user (except the transaction owner).
     *
     * @param  array<string, mixed>  $data
     * @param  User  $user  The user creating the transaction
     *
     * @throws InvalidArgumentException When split data validation fails
     */
    public function createTransaction(array $data, User $user): Transaction
    {
        if (($data['type'] ?? null) === 'expense' && ! empty($data['debt_id'])) {
            return $this->createDebtRepaymentExpense($data, $user);
        }

        if (($data['type'] ?? null) === 'income') {
            $data['is_split'] = false;
            $data['split_data'] = null;
            $data['advance_fund_id'] = null;
        }

        if ($data['is_split'] && ! empty($data['split_data'])) {
            if (! SplitCalculator::validate($data['split_data'])) {
                throw new InvalidArgumentException('Split percentages must sum to 100%.');
            }
        }

        return DB::transaction(function () use ($data, $user) {
            $transactionData = [
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'category_id' => $data['category_id'] ?? null,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'is_split' => $data['is_split'],
                'split_data' => $data['split_data'] ?? null,
                'advance_fund_id' => $data['advance_fund_id'] ?? null,
            ];

            $transaction = Transaction::query()->create($transactionData);

            if ($data['is_split'] && ! empty($data['split_data'])) {
                $allocatedSplits = SplitCalculator::allocate($data['amount'], $data['split_data']);

                foreach ($allocatedSplits as $split) {
                    TransactionSplit::query()->create([
                        'transaction_id' => $transaction->id,
                        'user_id' => $split['user_id'],
                        'share_percentage' => $split['share_percentage'],
                        'amount' => $split['amount'],
                    ]);

                    if ($split['user_id'] !== $user->id) {
                        Debt::query()->create([
                            'family_id' => $user->family_id,
                            'debtor_id' => $split['user_id'],
                            'creditor_id' => $user->id,
                            'transaction_id' => $transaction->id,
                            'amount' => $split['amount'],
                            'balance' => $split['amount'],
                            'description' => "Split from transaction #{$transaction->id}",
                            'is_pending_closeout' => true,
                        ]);
                    }
                }
            }

            return $transaction->load(['splits', 'debt.creditor', 'debt.debtor', 'debt.fund']);
        });
    }

    /**
     * Expense that repays a tracked debt: reduces balance immediately, creates a mirrored
     * income row for an in-family creditor when applicable.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function createDebtRepaymentExpense(array $data, User $user): Transaction
    {
        if ($data['is_split'] ?? false) {
            throw new InvalidArgumentException('A debt repayment cannot be split.');
        }

        return DB::transaction(function () use ($data, $user) {
            $debt = Debt::query()
                ->where('family_id', $user->family_id)
                ->lockForUpdate()
                ->findOrFail($data['debt_id']);

            $amount = round((float) $data['amount'], 2);

            if ($amount > round((float) $debt->balance, 2)) {
                throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
            }

            $payerExpense = Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'category_id' => $data['category_id'] ?? null,
                'type' => 'expense',
                'amount' => $amount,
                'description' => ($data['description'] ?? null) ?: 'Debt payment',
                'transaction_date' => $data['transaction_date'],
                'is_debt_payment' => true,
                'debt_id' => $debt->id,
                'paid_by_user_id' => $user->id,
                'is_closeout_initiated' => false,
                'is_split' => false,
                'split_data' => null,
                'advance_fund_id' => null,
            ]);

            $creditorIncome = null;
            if ($debt->creditor_id !== null) {
                $creditorIncome = Transaction::query()->create([
                    'family_id' => $user->family_id,
                    'user_id' => $debt->creditor_id,
                    'category_id' => null,
                    'type' => 'income',
                    'amount' => $amount,
                    'description' => ($data['description'] ?? null) ?: 'Debt repayment received',
                    'transaction_date' => $data['transaction_date'],
                    'is_debt_payment' => true,
                    'debt_id' => $debt->id,
                    'paid_by_user_id' => $user->id,
                    'is_closeout_initiated' => false,
                    'is_split' => false,
                    'split_data' => null,
                    'advance_fund_id' => null,
                ]);

                $payerExpense->forceFill(['mirror_transaction_id' => $creditorIncome->id])->save();
                $creditorIncome->forceFill(['mirror_transaction_id' => $payerExpense->id])->save();
            }

            $debt->decrement('balance', $amount);

            return $payerExpense->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund']);
        });
    }

    /**
     * Updates an existing transaction with optional split and debt records.
     *
     * Existing TransactionSplit and Debt records are deleted and recreated if needed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException When split data validation fails
     */
    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
        if ($transaction->is_debt_payment) {
            throw new InvalidArgumentException('Debt repayment transactions cannot be edited. Delete and recreate instead.');
        }

        if (($data['type'] ?? null) === 'income') {
            $data['is_split'] = false;
            $data['split_data'] = null;
            $data['advance_fund_id'] = null;
        }

        if ($data['is_split'] && ! empty($data['split_data'])) {
            if (! SplitCalculator::validate($data['split_data'])) {
                throw new InvalidArgumentException('Split percentages must sum to 100%.');
            }
        }

        return DB::transaction(function () use ($transaction, $data) {
            $transactionData = [
                'category_id' => $data['category_id'] ?? null,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'is_split' => $data['is_split'],
                'split_data' => $data['split_data'] ?? null,
                'advance_fund_id' => $data['advance_fund_id'] ?? null,
            ];

            $transaction->update($transactionData);

            $transaction->splits()->delete();
            Debt::query()->where('transaction_id', $transaction->id)->delete();

            if ($data['is_split'] && ! empty($data['split_data'])) {
                $allocatedSplits = SplitCalculator::allocate($data['amount'], $data['split_data']);

                foreach ($allocatedSplits as $split) {
                    TransactionSplit::query()->create([
                        'transaction_id' => $transaction->id,
                        'user_id' => $split['user_id'],
                        'share_percentage' => $split['share_percentage'],
                        'amount' => $split['amount'],
                    ]);

                    if ($split['user_id'] !== $transaction->user_id) {
                        Debt::query()->create([
                            'family_id' => $transaction->family_id,
                            'debtor_id' => $split['user_id'],
                            'creditor_id' => $transaction->user_id,
                            'transaction_id' => $transaction->id,
                            'amount' => $split['amount'],
                            'balance' => $split['amount'],
                            'description' => "Split from transaction #{$transaction->id}",
                            'is_pending_closeout' => true,
                        ]);
                    }
                }
            }

            return $transaction->load(['splits', 'debt.creditor', 'debt.debtor', 'debt.fund']);
        });
    }

    /**
     * Delete a transaction and reverse side-effects (mirrored debt payment, splits, split-linked debts).
     */
    public function deleteTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $partner = $this->resolveMirrorPartner($transaction);

            if ($partner) {
                $this->revertDebtBalanceForMirroredPayment($transaction, $partner);
                $this->clearMirrorsAndDelete(
                    collect([$transaction, $partner])->filter()->unique(fn (Transaction $row) => $row->id)
                );

                return;
            }

            if ($transaction->is_debt_payment && $transaction->debt_id) {
                $debt = Debt::query()->lockForUpdate()->find($transaction->debt_id);
                if ($debt) {
                    $debt->increment('balance', (float) $transaction->amount);
                }
            }

            $transaction->splits()->delete();
            Debt::query()->where('transaction_id', $transaction->id)->delete();
            $transaction->delete();
        });
    }

    private function resolveMirrorPartner(Transaction $transaction): ?Transaction
    {
        if ($transaction->mirror_transaction_id) {
            return Transaction::query()->lockForUpdate()->find($transaction->mirror_transaction_id);
        }

        return Transaction::query()
            ->where('mirror_transaction_id', $transaction->id)
            ->lockForUpdate()
            ->first();
    }

    private function revertDebtBalanceForMirroredPayment(Transaction $a, Transaction $b): void
    {
        $expenseLeg = $a->type === 'expense' ? $a : ($b->type === 'expense' ? $b : null);
        if ($expenseLeg && $expenseLeg->is_debt_payment && $expenseLeg->debt_id) {
            $debt = Debt::query()->lockForUpdate()->find($expenseLeg->debt_id);
            if ($debt) {
                $debt->increment('balance', (float) $expenseLeg->amount);
            }

            return;
        }

        $incomeLeg = $a->type === 'income' ? $a : ($b->type === 'income' ? $b : null);
        if ($incomeLeg && $incomeLeg->is_debt_payment && $incomeLeg->debt_id) {
            Debt::query()->lockForUpdate()->find($incomeLeg->debt_id)?->increment('balance', (float) $incomeLeg->amount);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     */
    private function clearMirrorsAndDelete(Collection $rows): void
    {
        foreach ($rows as $row) {
            $row->mirror_transaction_id = null;
            $row->save();
        }

        foreach ($rows as $row) {
            $row->splits()->delete();
            Debt::query()->where('transaction_id', $row->id)->delete();
            $row->delete();
        }
    }
}
