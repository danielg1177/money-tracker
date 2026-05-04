<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
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
     * @param array{
     *   category_id?: int|null,
     *   type: 'income'|'expense',
     *   amount: float,
     *   description?: string|null,
     *   transaction_date: string,
     *   is_split: bool,
     *   split_data?: array<array{user_id: int, percentage: float}>|null
     * } $data
     * @param  User  $user  The user creating the transaction
     * @return Transaction The created transaction with splits loaded
     *
     * @throws InvalidArgumentException When split data validation fails
     */
    public function createTransaction(array $data, User $user): Transaction
    {
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

            return $transaction->load('splits');
        });
    }

    /**
     * Updates an existing transaction with optional split and debt records.
     *
     * Existing TransactionSplit and Debt records are deleted and recreated if needed.
     *
     * @param array{
     *   category_id?: int|null,
     *   type: 'income'|'expense',
     *   amount: float,
     *   description?: string|null,
     *   transaction_date: string,
     *   is_split: bool,
     *   split_data?: array<array{user_id: int, percentage: float}>|null
     * } $data
     * @return Transaction The updated transaction with splits loaded
     *
     * @throws InvalidArgumentException When split data validation fails
     */
    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
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

            return $transaction->load('splits');
        });
    }
}
