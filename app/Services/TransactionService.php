<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionService
{
    public function __construct(
        private DebtService $debtService,
    ) {}

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

        if (($data['type'] ?? null) === 'income' && ! empty($data['is_debt_repayment_received'])) {
            return $this->createDebtRepaymentReceivedIncome($data, $user);
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
                'is_non_necessity' => ! empty($data['is_non_necessity']) && ($data['type'] ?? null) === 'expense' && empty($data['is_split']) && ! empty($data['advance_fund_id']),
                'debt_id' => $incomeDebt?->id,
                'is_loan_receipt' => ($data['income_debt_mode'] ?? 'none') === 'receipt',
            ];

            $transaction = Transaction::query()->create($transactionData);

            $this->patchIncomeAdditionTransactionId($incomeDebt, $transaction, $data);

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

            if ($debt->creditor_id === null && $amount > round((float) $debt->balance, 2)) {
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

            $previousBalance = round((float) $debt->balance, 2);
            $overpayment = round($amount - $previousBalance, 2);
            if ($overpayment > 0 && $debt->creditor_id !== null) {
                $debt->balance = '0.00';
                $debt->save();
                $this->debtService->applyInterFamilyPairNet(
                    (int) $debt->family_id,
                    (int) $debt->creditor_id,
                    (int) $debt->debtor_id,
                    $overpayment,
                    'Reversed from overpayment: '.((string) ($data['description'] ?? 'Debt payment')),
                    null,
                    (int) $debt->id,
                    (string) $data['transaction_date'],
                );
            } else {
                $debt->decrement('balance', $amount);
            }

            return $payerExpense->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund']);
        });
    }

    /**
     * Income recorded by the creditor when a family member repays a loan — creates the same
     * mirrored debt-payment pair as {@see createDebtRepaymentExpense()} would from the debtor.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private function createDebtRepaymentReceivedIncome(array $data, User $user): Transaction
    {
        return DB::transaction(function () use ($data, $user) {
            $debt = Debt::query()
                ->where('family_id', $user->family_id)
                ->lockForUpdate()
                ->findOrFail($data['debt_repayment_received_id']);

            if ($debt->creditor_id === null) {
                throw new InvalidArgumentException('Only in-family debts can be recorded as repayment received.');
            }

            if ((int) $debt->creditor_id !== (int) $user->id) {
                throw new InvalidArgumentException('Only the creditor can record this repayment received.');
            }

            if ($debt->is_pending_closeout) {
                throw new InvalidArgumentException('This debt is pending split closeout and cannot be repaid this way.');
            }

            $amount = round((float) $data['amount'], 2);

            if ($debt->creditor_id === null && $amount > round((float) $debt->balance, 2)) {
                throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
            }

            $debtorId = (int) $debt->debtor_id;
            $description = ($data['description'] ?? null) ?: 'Debt repayment received';
            $transactionDate = $data['transaction_date'];

            $creditorIncome = Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'category_id' => null,
                'type' => 'income',
                'amount' => $amount,
                'description' => $description,
                'transaction_date' => $transactionDate,
                'is_debt_payment' => true,
                'debt_id' => $debt->id,
                'paid_by_user_id' => $debtorId,
                'is_closeout_initiated' => false,
                'is_split' => false,
                'split_data' => null,
                'advance_fund_id' => null,
            ]);

            $debtorExpense = Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $debtorId,
                'category_id' => null,
                'type' => 'expense',
                'amount' => $amount,
                'description' => ($data['description'] ?? null) ?: 'Debt payment',
                'transaction_date' => $transactionDate,
                'is_debt_payment' => true,
                'debt_id' => $debt->id,
                'paid_by_user_id' => $debtorId,
                'is_closeout_initiated' => false,
                'is_split' => false,
                'split_data' => null,
                'advance_fund_id' => null,
            ]);

            $creditorIncome->forceFill(['mirror_transaction_id' => $debtorExpense->id])->save();
            $debtorExpense->forceFill(['mirror_transaction_id' => $creditorIncome->id])->save();

            $previousBalance = round((float) $debt->balance, 2);
            $overpayment = round($amount - $previousBalance, 2);
            if ($overpayment > 0 && $debt->creditor_id !== null) {
                $debt->balance = '0.00';
                $debt->save();
                $this->debtService->applyInterFamilyPairNet(
                    (int) $debt->family_id,
                    (int) $debt->creditor_id,
                    (int) $debt->debtor_id,
                    $overpayment,
                    'Reversed from overpayment: '.($description ?: 'Debt repayment received'),
                    null,
                    (int) $debt->id,
                    (string) $transactionDate,
                );
            } else {
                $debt->decrement('balance', $amount);
            }

            return $creditorIncome->load(['user', 'category', 'debt.creditor', 'debt.debtor', 'debt.fund', 'mirrorTransaction']);
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
        if ($transaction->is_debt_payment_benefit) {
            throw new InvalidArgumentException('Edit this expense via the debt repayment benefit endpoints.');
        }

        if ($transaction->is_debt_payment) {
            if (empty($data['debt_id'])) {
                return $this->unlinkDebtPaymentTransaction($transaction, $data);
            }

            return $this->updateDebtRepaymentTransaction($transaction, $data);
        }

        if (($data['type'] ?? null) === 'expense' && ! empty($data['debt_id'])) {
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
                'is_non_necessity' => ! empty($data['is_non_necessity']) && ($data['type'] ?? null) === 'expense' && empty($data['is_split']) && ! empty($data['advance_fund_id']),
                'debt_id' => $incomeDebt?->id,
                'is_loan_receipt' => ($data['income_debt_mode'] ?? 'none') === 'receipt',
            ];

            $transaction->update($transactionData);

            $this->patchIncomeAdditionTransactionId($incomeDebt, $transaction, $data);

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

            if (! $oldDebt && $transaction->is_debt_payment) {
                throw new InvalidArgumentException('Original debt was not found.');
            }

            $newDebt = Debt::query()
                ->where('family_id', $transaction->family_id)
                ->lockForUpdate()
                ->findOrFail($data['debt_id']);

            $newAmount = round((float) ($data['amount'] ?? 0), 2);
            $oldAmount = round((float) $transaction->amount, 2);

            if ($oldDebt) {
                $oldDebt->increment('balance', $oldAmount);
            }

            if ($newAmount > round((float) $newDebt->balance, 2)) {
                throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
            }

            $newDebt->decrement('balance', $newAmount);

            $transaction->update([
                'category_id' => $data['category_id'] ?? null,
                'type' => 'expense',
                'amount' => $newAmount,
                'description' => ($data['description'] ?? null) ?: 'Debt payment',
                'transaction_date' => $data['transaction_date'],
                'is_split' => $hasSplit,
                'split_data' => $hasSplit ? $splitData : null,
                'advance_fund_id' => null,
                'debt_id' => $newDebt->id,
                'is_debt_payment' => true,
                'is_loan_receipt' => false,
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
                    $this->syncDebtPaymentBenefitFromIncome($existingMirror);
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
                    $this->deleteDebtPaymentBenefitForIncome($existingMirror);
                    $existingMirror->forceFill(['mirror_transaction_id' => null])->save();
                    $existingMirror->delete();
                }
                $transaction->forceFill(['mirror_transaction_id' => null])->save();
            }

            return $transaction->load(['splits', 'debt.creditor', 'debt.debtor', 'debt.fund']);
        });
    }

    /**
     * Record a creditor-side benefit expense linked to a debt-payment income row.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public function createDebtPaymentBenefit(Transaction $income, array $data, User $user): Transaction
    {
        $this->assertDebtPaymentIncomeForBenefit($income, $user);

        if ($income->debtPaymentBenefitExpense()->exists()) {
            throw new InvalidArgumentException('A benefit expense has already been recorded for this repayment.');
        }

        return DB::transaction(function () use ($income, $data) {
            $hasSplit = (bool) ($data['is_split'] ?? false);
            $splitData = $hasSplit ? ($data['split_data'] ?? []) : [];
            if ($hasSplit && ! SplitCalculator::validate($splitData)) {
                throw new InvalidArgumentException('Split percentages must sum to 100%.');
            }

            $amount = round((float) $income->amount, 2);
            $advanceFundId = $hasSplit ? null : ($data['advance_fund_id'] ?? null);
            $isNonNecessity = ! empty($data['is_non_necessity']) && ! $hasSplit && ! empty($advanceFundId);

            $benefit = Transaction::query()->create([
                'family_id' => $income->family_id,
                'user_id' => $income->user_id,
                'category_id' => $data['category_id'],
                'type' => 'expense',
                'amount' => $amount,
                'description' => $data['description'] ?? $income->description,
                'transaction_date' => $income->transaction_date,
                'is_split' => $hasSplit,
                'split_data' => $hasSplit ? $splitData : null,
                'advance_fund_id' => $advanceFundId,
                'is_non_necessity' => $isNonNecessity,
                'is_debt_payment' => false,
                'is_debt_payment_benefit' => true,
                'debt_payment_income_id' => $income->id,
                'debt_id' => null,
                'is_closeout_initiated' => false,
            ]);

            $this->applyBenefitSplits($benefit, $amount, $hasSplit, $splitData);

            return $benefit->load(['user', 'category', 'splits.user', 'advanceFund', 'debtPaymentIncome']);
        });
    }

    /**
     * Update the creditor-side benefit expense linked to a debt-payment income row.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public function updateDebtPaymentBenefit(Transaction $income, array $data, User $user): Transaction
    {
        $this->assertDebtPaymentIncomeForBenefit($income, $user);

        $benefit = $income->debtPaymentBenefitExpense;
        if ($benefit === null) {
            throw new InvalidArgumentException('No benefit expense exists for this repayment.');
        }

        return DB::transaction(function () use ($income, $benefit, $data) {
            $hasSplit = (bool) ($data['is_split'] ?? false);
            $splitData = $hasSplit ? ($data['split_data'] ?? []) : [];
            if ($hasSplit && ! SplitCalculator::validate($splitData)) {
                throw new InvalidArgumentException('Split percentages must sum to 100%.');
            }

            $amount = round((float) $income->amount, 2);
            $advanceFundId = $hasSplit ? null : ($data['advance_fund_id'] ?? null);
            $isNonNecessity = ! empty($data['is_non_necessity']) && ! $hasSplit && ! empty($advanceFundId);

            $benefit->update([
                'category_id' => $data['category_id'],
                'amount' => $amount,
                'description' => $data['description'] ?? $benefit->description,
                'transaction_date' => $income->transaction_date,
                'is_split' => $hasSplit,
                'split_data' => $hasSplit ? $splitData : null,
                'advance_fund_id' => $advanceFundId,
                'is_non_necessity' => $isNonNecessity,
                'is_debt_payment_benefit' => true,
                'debt_payment_income_id' => $income->id,
            ]);

            $benefit->splits()->delete();
            Debt::query()->where('transaction_id', $benefit->id)->delete();
            $this->applyBenefitSplits($benefit, $amount, $hasSplit, $splitData);

            return $benefit->fresh()->load(['user', 'category', 'splits.user', 'advanceFund', 'debtPaymentIncome']);
        });
    }

    /**
     * Remove the creditor-side benefit expense without touching the debt-payment pair.
     *
     * @throws InvalidArgumentException
     */
    public function deleteDebtPaymentBenefit(Transaction $income, User $user): void
    {
        $this->assertDebtPaymentIncomeForBenefit($income, $user);

        $benefit = $income->debtPaymentBenefitExpense;
        if ($benefit === null) {
            throw new InvalidArgumentException('No benefit expense exists for this repayment.');
        }

        DB::transaction(function () use ($benefit): void {
            $this->purgeBenefitExpense($benefit);
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertDebtPaymentIncomeForBenefit(Transaction $income, User $user): void
    {
        if ((int) $income->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Only the creditor can record a benefit expense for this repayment.');
        }

        if ($income->type !== 'income' || ! $income->is_debt_payment) {
            throw new InvalidArgumentException('Benefit expenses can only be linked to debt repayment income.');
        }

        $income->loadMissing('debt');
        if ($income->debt === null || $income->debt->creditor_id === null) {
            throw new InvalidArgumentException('Benefit expenses are only allowed for in-family debt repayments.');
        }

        if ((int) $income->debt->creditor_id !== (int) $user->id) {
            throw new InvalidArgumentException('Only the creditor can record a benefit expense for this repayment.');
        }
    }

    /**
     * @param  array<int, array{user_id: int, share_percentage: float|int|string}>  $splitData
     */
    private function applyBenefitSplits(Transaction $benefit, float $amount, bool $hasSplit, array $splitData): void
    {
        if (! $hasSplit || empty($splitData)) {
            return;
        }

        $allocatedSplits = SplitCalculator::allocate($amount, $splitData);
        foreach ($allocatedSplits as $split) {
            TransactionSplit::query()->create([
                'transaction_id' => $benefit->id,
                'user_id' => $split['user_id'],
                'share_percentage' => $split['share_percentage'],
                'amount' => $split['amount'],
            ]);

            if ((int) $split['user_id'] !== (int) $benefit->user_id) {
                Debt::query()->create([
                    'family_id' => $benefit->family_id,
                    'debtor_id' => $split['user_id'],
                    'creditor_id' => $benefit->user_id,
                    'transaction_id' => $benefit->id,
                    'amount' => $split['amount'],
                    'balance' => $split['amount'],
                    'description' => "Split from transaction #{$benefit->id}",
                    'is_pending_closeout' => true,
                ]);
            }
        }
    }

    private function syncDebtPaymentBenefitFromIncome(Transaction $income): void
    {
        $benefit = Transaction::query()
            ->where('debt_payment_income_id', $income->id)
            ->lockForUpdate()
            ->first();

        if ($benefit === null) {
            return;
        }

        if ((int) $benefit->user_id !== (int) $income->user_id) {
            $this->purgeBenefitExpense($benefit);

            return;
        }

        $amount = round((float) $income->amount, 2);
        $hasSplit = (bool) $benefit->is_split;
        $splitData = $hasSplit ? ($benefit->split_data ?? []) : [];

        $benefit->update([
            'amount' => $amount,
            'transaction_date' => $income->transaction_date,
        ]);

        if ($hasSplit && ! empty($splitData) && SplitCalculator::validate($splitData)) {
            $benefit->splits()->delete();
            Debt::query()->where('transaction_id', $benefit->id)->delete();
            $this->applyBenefitSplits($benefit, $amount, true, $splitData);
        }
    }

    private function deleteDebtPaymentBenefitForIncome(Transaction $income): void
    {
        $benefit = Transaction::query()
            ->where('debt_payment_income_id', $income->id)
            ->lockForUpdate()
            ->first();

        if ($benefit !== null) {
            $this->purgeBenefitExpense($benefit);
        }
    }

    private function purgeBenefitExpense(Transaction $benefit): void
    {
        $benefit->splits()->delete();
        Debt::query()->where('transaction_id', $benefit->id)->delete();
        $benefit->delete();
    }

    private function deleteDebtPaymentBenefitAroundPair(Transaction $a, ?Transaction $b): void
    {
        foreach ([$a, $b] as $row) {
            if ($row === null) {
                continue;
            }

            if ($row->type === 'income' && $row->is_debt_payment) {
                $this->deleteDebtPaymentBenefitForIncome($row);
            }
        }
    }

    /**
     * Delete a transaction and reverse side-effects (mirrored debt payment, splits, split-linked debts).
     */
    public function deleteTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $this->rollbackIncomeDebtAssociation($transaction);
            $this->resetLinkedPendingImport($transaction);
            $partner = $this->resolveMirrorPartner($transaction);

            if ($partner) {
                $this->deleteDebtPaymentBenefitAroundPair($transaction, $partner);
                $this->revertDebtBalanceForMirroredPayment($transaction, $partner);
                $this->clearMirrorsAndDelete(
                    collect([$transaction, $partner])->filter()->unique(fn (Transaction $row) => $row->id)
                );

                return;
            }

            if ($transaction->is_debt_payment && $transaction->type === 'income') {
                $this->deleteDebtPaymentBenefitForIncome($transaction);
            }

            if ($transaction->is_debt_payment_benefit) {
                $this->purgeBenefitExpense($transaction);

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
     * Reset the Plaid pending import linked to this transaction back to pending
     * so it reappears in the review queue after the transaction is deleted.
     */
    private function resetLinkedPendingImport(Transaction $transaction): void
    {
        PlaidPendingImport::query()
            ->where('transaction_id', $transaction->id)
            ->update(['status' => 'pending', 'transaction_id' => null]);
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

            $debt->increment('balance', $amount);

            $additions = $debt->fresh()->income_additions ?? [];
            $additions[] = [
                'transaction_id' => null,
                'amount' => $amount,
                'date' => $data['transaction_date'] ?? now()->toDateString(),
            ];
            $debt->update(['income_additions' => $additions]);

            return $debt->fresh();
        }

        if ($mode === 'receipt') {
            return Debt::query()
                ->where('family_id', $user->family_id)
                ->lockForUpdate()
                ->findOrFail($data['income_existing_debt_id']);
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
        if ($transaction->is_loan_receipt) {
            return;
        }

        if ($transaction->type !== 'income' || $transaction->is_debt_payment || ! $transaction->debt_id) {
            return;
        }

        $debt = Debt::query()->lockForUpdate()->find($transaction->debt_id);
        if (! $debt) {
            return;
        }

        $amount = round((float) $transaction->amount, 2);
        $additions = $debt->income_additions ?? [];
        $matchKey = null;
        foreach ($additions as $k => $entry) {
            if ((int) ($entry['transaction_id'] ?? 0) === $transaction->id) {
                $matchKey = $k;
                break;
            }
        }

        if ($matchKey !== null) {
            $nextBalance = max(0, round((float) $debt->balance - $amount, 2));
            unset($additions[$matchKey]);
            $debt->update([
                'balance' => $nextBalance,
                'income_additions' => array_values($additions),
            ]);

            return;
        }

        $nextAmount = max(0, round((float) $debt->amount - $amount, 2));
        $nextBalance = max(0, round((float) $debt->balance - $amount, 2));
        $debt->update([
            'amount' => $nextAmount,
            'balance' => $nextBalance,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function patchIncomeAdditionTransactionId(?Debt $incomeDebt, Transaction $transaction, array $data): void
    {
        if ($incomeDebt === null || ($data['income_debt_mode'] ?? 'none') !== 'existing') {
            return;
        }

        $freshDebt = $incomeDebt->fresh();
        $additions = $freshDebt->income_additions ?? [];
        $lastKey = null;
        foreach ($additions as $k => $entry) {
            if ($entry['transaction_id'] === null && abs((float) $entry['amount'] - round((float) $transaction->amount, 2)) < 0.005) {
                $lastKey = $k;
            }
        }

        if ($lastKey !== null) {
            $additions[$lastKey]['transaction_id'] = $transaction->id;
            $freshDebt->update(['income_additions' => $additions]);
        }
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
            $this->resetLinkedPendingImport($row);
            $row->splits()->delete();
            Debt::query()->where('transaction_id', $row->id)->delete();
            $row->delete();
        }
    }

    /**
     * Converts an expense debt-payment transaction back into a regular expense.
     *
     * Restores the debt balance, deletes the mirror income transaction (if any),
     * and clears all debt-payment flags before applying the incoming field updates.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException When the transaction type is incompatible
     */
    private function unlinkDebtPaymentTransaction(Transaction $transaction, array $data): Transaction
    {
        if ($transaction->type !== 'expense') {
            throw new InvalidArgumentException('Only expense debt payments can be unlinked from a debt.');
        }

        if (($data['type'] ?? null) !== 'expense') {
            throw new InvalidArgumentException('A debt payment can only be unlinked while keeping the type as expense.');
        }

        $hasSplit = (bool) ($data['is_split'] ?? false);
        $splitData = $hasSplit ? ($data['split_data'] ?? []) : [];
        if ($hasSplit && ! SplitCalculator::validate($splitData)) {
            throw new InvalidArgumentException('Split percentages must sum to 100%.');
        }

        return DB::transaction(function () use ($transaction, $data, $hasSplit, $splitData) {
            $oldDebt = Debt::query()->lockForUpdate()->find($transaction->debt_id);
            if ($oldDebt) {
                $oldDebt->increment('balance', (float) $transaction->amount);
            }

            $mirror = $this->resolveMirrorPartner($transaction);
            if ($mirror) {
                $this->deleteDebtPaymentBenefitForIncome($mirror);
                $mirror->forceFill(['mirror_transaction_id' => null])->save();
                $mirror->splits()->delete();
                Debt::query()->where('transaction_id', $mirror->id)->delete();
                $mirror->delete();
            }
            $transaction->forceFill(['mirror_transaction_id' => null])->save();

            $newAmount = round((float) ($data['amount'] ?? 0), 2);

            $transaction->update([
                'category_id' => $data['category_id'] ?? null,
                'type' => 'expense',
                'amount' => $newAmount,
                'description' => $data['description'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'is_split' => $hasSplit,
                'split_data' => $hasSplit ? $splitData : null,
                'advance_fund_id' => $data['advance_fund_id'] ?? null,
                'is_non_necessity' => ! empty($data['is_non_necessity']) && empty($hasSplit) && ! empty($data['advance_fund_id']),
                'debt_id' => null,
                'is_debt_payment' => false,
                'is_loan_receipt' => false,
                'paid_by_user_id' => null,
            ]);

            $transaction->splits()->delete();
            Debt::query()->where('transaction_id', $transaction->id)->delete();

            if ($hasSplit && ! empty($splitData)) {
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
                            'description' => "Split from transaction #{$transaction->id}",
                            'is_pending_closeout' => true,
                        ]);
                    }
                }
            }

            return $transaction->load(['splits', 'debt.creditor', 'debt.debtor', 'debt.fund']);
        });
    }
}
