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
            $incomeDebt = $this->resolveIncomeDebtForIncomeTransaction($data, $user);

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
                'debt_id' => $incomeDebt?->id,
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
        return DB::transaction(function () use ($data, $user) {
            $debt = Debt::query()
                ->where('family_id', $user->family_id)
                ->lockForUpdate()
                ->findOrFail($data['debt_id']);

            $amount = round((float) $data['amount'], 2);

            if ($amount > round((float) $debt->balance, 2)) {
                throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
            }

            $hasSplit = (bool) ($data['is_split'] ?? false);
            $splitData = $hasSplit ? ($data['split_data'] ?? []) : [];
            if ($hasSplit && ! SplitCalculator::validate($splitData)) {
                throw new InvalidArgumentException('Split percentages must sum to 100%.');
            }
            $storedSplitData = $hasSplit ? $splitData : null;

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
                'is_split' => $hasSplit,
                'split_data' => $storedSplitData,
                'advance_fund_id' => null,
            ]);

            if ($hasSplit) {
                $allocatedSplits = SplitCalculator::allocate($amount, $splitData);
                foreach ($allocatedSplits as $split) {
                    TransactionSplit::query()->create([
                        'transaction_id' => $payerExpense->id,
                        'user_id' => $split['user_id'],
                        'share_percentage' => $split['share_percentage'],
                        'amount' => $split['amount'],
                    ]);

                    if ((int) $split['user_id'] !== (int) $user->id) {
                        Debt::query()->create([
                            'family_id' => $user->family_id,
                            'debtor_id' => $split['user_id'],
                            'creditor_id' => $user->id,
                            'transaction_id' => $payerExpense->id,
                            'amount' => $split['amount'],
                            'balance' => $split['amount'],
                            'description' => 'Split from debt payment: '.((string) ($data['description'] ?? 'Debt payment')),
                            'is_pending_closeout' => true,
                        ]);
                    }
                }
            }

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
            return $this->updateDebtRepaymentTransaction($transaction, $data);
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
            $this->rollbackIncomeDebtAssociation($transaction);
            $incomeDebt = $this->resolveIncomeDebtForIncomeTransaction($data, $transaction->user);

            $transactionData = [
                'category_id' => $data['category_id'] ?? null,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'is_split' => $data['is_split'],
                'split_data' => $data['split_data'] ?? null,
                'advance_fund_id' => $data['advance_fund_id'] ?? null,
                'debt_id' => $incomeDebt?->id,
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
     * @param  array<string, mixed>  $data
     */
    private function updateDebtRepaymentTransaction(Transaction $transaction, array $data): Transaction
    {
        if ($transaction->type !== 'expense') {
            throw new InvalidArgumentException('Only debt payment expense transactions can be edited.');
        }

        if (empty($data['debt_id'])) {
            throw new InvalidArgumentException('Debt repayment edits must remain linked to a debt.');
        }

        if (($data['type'] ?? null) !== 'expense') {
            throw new InvalidArgumentException('Debt repayment edits must remain an expense transaction.');
        }

        $hasSplit = (bool) ($data['is_split'] ?? false);
        $splitData = $hasSplit ? ($data['split_data'] ?? []) : [];
        if ($hasSplit && ! SplitCalculator::validate($splitData)) {
            throw new InvalidArgumentException('Split percentages must sum to 100%.');
        }

        return DB::transaction(function () use ($transaction, $data, $hasSplit, $splitData) {
            $existingMirror = $this->resolveMirrorPartner($transaction);
            $oldDebt = Debt::query()->lockForUpdate()->find($transaction->debt_id);
            if (! $oldDebt) {
                throw new InvalidArgumentException('Original debt was not found.');
            }

            $newDebt = Debt::query()
                ->where('family_id', $transaction->family_id)
                ->lockForUpdate()
                ->findOrFail($data['debt_id']);

            $newAmount = round((float) ($data['amount'] ?? 0), 2);
            $oldAmount = round((float) $transaction->amount, 2);

            $oldDebt->increment('balance', $oldAmount);

            if ($newAmount > round((float) $newDebt->balance, 2)) {
                throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
            }

            $newDebt->decrement('balance', $newAmount);

            $transaction->update([
                'category_id' => $data['category_id'] ?? null,
                'amount' => $newAmount,
                'description' => ($data['description'] ?? null) ?: 'Debt payment',
                'transaction_date' => $data['transaction_date'],
                'is_split' => $hasSplit,
                'split_data' => $hasSplit ? $splitData : null,
                'advance_fund_id' => null,
                'debt_id' => $newDebt->id,
                'paid_by_user_id' => $transaction->user_id,
            ]);

            $transaction->splits()->delete();
            Debt::query()->where('transaction_id', $transaction->id)->delete();

            if ($hasSplit) {
                $allocatedSplits = SplitCalculator::allocate($newAmount, $splitData);
                foreach ($allocatedSplits as $split) {
                    TransactionSplit::query()->create([
                        'transaction_id' => $transaction->id,
                        'user_id' => $split['user_id'],
                        'share_percentage' => $split['share_percentage'],
                        'amount' => $split['amount'],
                    ]);

                    if ((int) $split['user_id'] !== (int) $transaction->user_id) {
                        Debt::query()->create([
                            'family_id' => $transaction->family_id,
                            'debtor_id' => $split['user_id'],
                            'creditor_id' => $transaction->user_id,
                            'transaction_id' => $transaction->id,
                            'amount' => $split['amount'],
                            'balance' => $split['amount'],
                            'description' => 'Split from debt payment: '.((string) ($data['description'] ?? 'Debt payment')),
                            'is_pending_closeout' => true,
                        ]);
                    }
                }
            }

            if ($newDebt->creditor_id !== null) {
                if ($existingMirror) {
                    $existingMirror->update([
                        'family_id' => $transaction->family_id,
                        'user_id' => $newDebt->creditor_id,
                        'category_id' => null,
                        'type' => 'income',
                        'amount' => $newAmount,
                        'description' => ($data['description'] ?? null) ?: 'Debt repayment received',
                        'transaction_date' => $data['transaction_date'],
                        'is_debt_payment' => true,
                        'debt_id' => $newDebt->id,
                        'paid_by_user_id' => $transaction->user_id,
                        'is_closeout_initiated' => false,
                        'is_split' => false,
                        'split_data' => null,
                        'advance_fund_id' => null,
                    ]);
                } else {
                    $existingMirror = Transaction::query()->create([
                        'family_id' => $transaction->family_id,
                        'user_id' => $newDebt->creditor_id,
                        'category_id' => null,
                        'type' => 'income',
                        'amount' => $newAmount,
                        'description' => ($data['description'] ?? null) ?: 'Debt repayment received',
                        'transaction_date' => $data['transaction_date'],
                        'is_debt_payment' => true,
                        'debt_id' => $newDebt->id,
                        'paid_by_user_id' => $transaction->user_id,
                        'is_closeout_initiated' => false,
                        'is_split' => false,
                        'split_data' => null,
                        'advance_fund_id' => null,
                        'mirror_transaction_id' => $transaction->id,
                    ]);
                }

                $transaction->forceFill(['mirror_transaction_id' => $existingMirror->id])->save();
                $existingMirror->forceFill(['mirror_transaction_id' => $transaction->id])->save();
            } else {
                if ($existingMirror) {
                    $existingMirror->forceFill(['mirror_transaction_id' => null])->save();
                    $existingMirror->delete();
                }
                $transaction->forceFill(['mirror_transaction_id' => null])->save();
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
            $this->rollbackIncomeDebtAssociation($transaction);
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveIncomeDebtForIncomeTransaction(array $data, User $user): ?Debt
    {
        if (($data['type'] ?? null) !== 'income') {
            return null;
        }

        $mode = (string) ($data['income_debt_mode'] ?? 'none');
        if ($mode === 'none') {
            return null;
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Income amount must be greater than zero.');
        }

        if ($mode === 'existing') {
            $debt = Debt::query()
                ->where('family_id', $user->family_id)
                ->lockForUpdate()
                ->findOrFail($data['income_existing_debt_id']);

            $debt->increment('amount', $amount);
            $debt->increment('balance', $amount);

            return $debt->fresh();
        }

        if ($mode !== 'new') {
            throw new InvalidArgumentException('Invalid income debt mode.');
        }

        return Debt::query()->create([
            'family_id' => $user->family_id,
            'debtor_id' => $user->id,
            'creditor_id' => ! empty($data['income_new_is_interfamily']) ? $data['income_new_creditor_id'] : null,
            'creditor_name' => ! empty($data['income_new_is_interfamily']) ? null : ($data['income_new_creditor_name'] ?? null),
            'amount' => $amount,
            'balance' => $amount,
            'description' => $data['income_new_description'] ?? ($data['description'] ?? null),
            'is_family_debt' => (bool) ($data['income_new_is_family_debt'] ?? false),
            'interest_enabled' => (bool) ($data['income_new_interest_enabled'] ?? false),
            'interest_rate' => ! empty($data['income_new_interest_enabled'])
                ? (float) ($data['income_new_interest_rate'] ?? 0)
                : null,
            'loan_received_date' => $data['transaction_date'],
            'is_pending_closeout' => false,
        ]);
    }

    private function rollbackIncomeDebtAssociation(Transaction $transaction): void
    {
        if ($transaction->type !== 'income' || $transaction->is_debt_payment || ! $transaction->debt_id) {
            return;
        }

        $debt = Debt::query()->lockForUpdate()->find($transaction->debt_id);
        if (! $debt) {
            return;
        }

        $amount = round((float) $transaction->amount, 2);
        $nextAmount = max(0, round((float) $debt->amount - $amount, 2));
        $nextBalance = max(0, round((float) $debt->balance - $amount, 2));
        $debt->update([
            'amount' => $nextAmount,
            'balance' => $nextBalance,
        ]);
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
