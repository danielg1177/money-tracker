<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionRepaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionRepaymentService
{
    /**
     * Link an income repayment transaction to one or more repaid expenses and create mirror rows.
     *
     * @param  array<int, array{transaction_id: int, amount: float}>  $links
     */
    public function createRepaymentLinks(Transaction $repaymentIncome, array $links, User $repaidUser): void
    {
        DB::transaction(function () use ($repaymentIncome, $links, $repaidUser): void {
            $repaymentIncome->forceFill(['is_repayment' => true])->save();

            foreach ($links as $entry) {
                $repaidExpense = Transaction::query()->find($entry['transaction_id']);

                if ($repaidExpense === null) {
                    throw new InvalidArgumentException('Repaid expense transaction not found.');
                }

                if ($repaidExpense->family_id !== $repaymentIncome->family_id) {
                    throw new InvalidArgumentException('Repaid expense must belong to the same family as the repayment income.');
                }

                if ($repaidExpense->type !== 'expense') {
                    throw new InvalidArgumentException('Repaid transaction must be an expense.');
                }

                if ($repaidExpense->is_repaid) {
                    throw new InvalidArgumentException('Repaid expense is already linked to a repayment.');
                }

                $repaidExpense->forceFill(['is_repaid' => true])->save();

                $mirrorExpense = Transaction::query()->create([
                    'family_id' => $repaymentIncome->family_id,
                    'user_id' => $repaidUser->id,
                    'category_id' => $repaidExpense->category_id,
                    'type' => 'expense',
                    'amount' => $entry['amount'],
                    'description' => $repaidExpense->description,
                    'transaction_date' => $repaymentIncome->transaction_date,
                    'is_split' => false,
                    'split_data' => null,
                    'advance_fund_id' => null,
                    'is_non_necessity' => false,
                    'is_repayment_mirror' => true,
                ]);

                TransactionRepaymentLink::query()->create([
                    'repayment_transaction_id' => $repaymentIncome->id,
                    'repaid_transaction_id' => $repaidExpense->id,
                    'mirror_transaction_id' => $mirrorExpense->id,
                    'repaid_user_id' => $repaidUser->id,
                    'amount' => $entry['amount'],
                ]);
            }
        });
    }

    /**
     * Remove all repayment links for an income transaction and revert repaid/mirror rows.
     */
    public function deleteRepaymentLinks(Transaction $repaymentIncome): void
    {
        DB::transaction(function () use ($repaymentIncome): void {
            $links = TransactionRepaymentLink::query()
                ->where('repayment_transaction_id', $repaymentIncome->id)
                ->with('mirrorTransaction')
                ->get();

            foreach ($links as $link) {
                $repaidExpense = Transaction::query()->find($link->repaid_transaction_id);

                if ($repaidExpense !== null) {
                    $repaidExpense->forceFill(['is_repaid' => false])->save();
                }

                if ($link->mirror_transaction_id !== null) {
                    $mirror = $link->mirrorTransaction ?? Transaction::query()->find($link->mirror_transaction_id);

                    if ($mirror !== null) {
                        $mirror->delete();
                    }
                }

                $link->delete();
            }

            $repaymentIncome->forceFill(['is_repayment' => false])->save();
        });
    }

    /**
     * Called after creating or updating a transaction. Handles adding repayment links if is_repayment_mode is set.
     *
     * @param  array<string, mixed>  $validatedData
     */
    public function handleRepaymentForTransaction(Transaction $transaction, array $validatedData): void
    {
        if (! ($validatedData['is_repayment_mode'] ?? false)) {
            $this->deleteRepaymentLinks($transaction);

            return;
        }

        $repaidUserId = $validatedData['repayment_for_user_id'] ?? null;
        $links = $validatedData['repayment_links'] ?? [];

        $repaidUser = User::query()->find($repaidUserId);

        if ($repaidUser === null) {
            throw new InvalidArgumentException('Repayment recipient user not found.');
        }

        if ($repaidUser->family_id !== $transaction->family_id) {
            throw new InvalidArgumentException('Repayment recipient must belong to the same family as the transaction.');
        }

        $this->deleteRepaymentLinks($transaction);
        $this->createRepaymentLinks($transaction, $links, $repaidUser);
    }
}
