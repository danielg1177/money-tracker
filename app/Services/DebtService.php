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
     * Apply a net amount that $debtorId owes $creditorId against confirmed in-family
     * running debts for that pair. Opposite-direction balances are reduced first; any
     * remainder increases an existing same-direction debt or creates one.
     *
     * This prevents bidirectional open debts (A→B and B→A) after closeout consolidation
     * or in-family overpayment swings.
     *
     * @param  array{month: int, year: int}|null  $closeoutContribution  When set, writes
     *                                                                   undoable contribution
     *                                                                   entries (negative when
     *                                                                   reducing opposite debts).
     * @param  int|null  $reversedFromDebtId  When set, links a newly created reverse debt to the
     *                                        overpaid source, or records direction_reversals when
     *                                        merging into an existing reverse debt.
     * @param  string|null  $occurredOn  Date for stored reversal timeline rows (`Y-m-d`).
     */
    public function applyInterFamilyPairNet(
        int $familyId,
        int $debtorId,
        int $creditorId,
        float $amount,
        ?string $description = null,
        ?array $closeoutContribution = null,
        ?int $reversedFromDebtId = null,
        ?string $occurredOn = null,
    ): void {
        $remaining = round($amount, 2);
        if ($remaining < 0.01 || $debtorId === $creditorId) {
            return;
        }

        $oppositeDebts = Debt::query()
            ->where('family_id', $familyId)
            ->where('debtor_id', $creditorId)
            ->where('creditor_id', $debtorId)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->where('balance', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($oppositeDebts as $oppositeDebt) {
            if ($remaining < 0.01) {
                break;
            }

            $applied = min($remaining, round((float) $oppositeDebt->balance, 2));
            if ($applied < 0.01) {
                continue;
            }

            $oppositeDebt->balance = round((float) $oppositeDebt->balance - $applied, 2);
            $oppositeDebt->amount = max(0, round((float) $oppositeDebt->amount - $applied, 2));

            if ($closeoutContribution !== null) {
                $oppositeDebt->contributions = array_merge($oppositeDebt->contributions ?? [], [[
                    'month' => $closeoutContribution['month'],
                    'year' => $closeoutContribution['year'],
                    'amount' => -$applied,
                ]]);
            }

            $oppositeDebt->save();
            $remaining = round($remaining - $applied, 2);
        }

        if ($remaining < 0.01) {
            return;
        }

        $sameDirectionDebt = Debt::query()
            ->where('family_id', $familyId)
            ->where('debtor_id', $debtorId)
            ->where('creditor_id', $creditorId)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->orderByDesc('balance')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($sameDirectionDebt) {
            $sameDirectionDebt->amount = round((float) $sameDirectionDebt->amount + $remaining, 2);
            $sameDirectionDebt->balance = round((float) $sameDirectionDebt->balance + $remaining, 2);

            if ($closeoutContribution !== null) {
                $sameDirectionDebt->contributions = array_merge($sameDirectionDebt->contributions ?? [], [[
                    'month' => $closeoutContribution['month'],
                    'year' => $closeoutContribution['year'],
                    'amount' => $remaining,
                ]]);
            }

            if ($reversedFromDebtId !== null) {
                $appliedAt = $occurredOn ?: Carbon::today()->toDateString();
                $sameDirectionDebt->direction_reversals = array_values(array_merge(
                    $sameDirectionDebt->direction_reversals ?? [],
                    [[
                        'amount' => $remaining,
                        'source_debt_id' => $reversedFromDebtId,
                        'applied_at' => $appliedAt,
                    ]],
                ));
            }

            $sameDirectionDebt->save();

            if ($reversedFromDebtId !== null) {
                $sourceDebt = Debt::query()
                    ->whereKey($reversedFromDebtId)
                    ->lockForUpdate()
                    ->first();
                if ($sourceDebt) {
                    $sourceDebt->direction_reversals = array_values(array_merge(
                        $sourceDebt->direction_reversals ?? [],
                        [[
                            'amount' => $remaining,
                            'target_debt_id' => $sameDirectionDebt->id,
                            'applied_at' => $occurredOn ?: Carbon::today()->toDateString(),
                        ]],
                    ));
                    $sourceDebt->save();
                }
            }

            return;
        }

        $contribution = null;
        if ($closeoutContribution !== null) {
            $contribution = [
                'month' => $closeoutContribution['month'],
                'year' => $closeoutContribution['year'],
                'amount' => $remaining,
                'created_by_closeout_debt' => true,
            ];
        }

        Debt::query()->create([
            'family_id' => $familyId,
            'debtor_id' => $debtorId,
            'creditor_id' => $creditorId,
            'amount' => $remaining,
            'balance' => $remaining,
            'description' => $description ?: 'Inter-family debt',
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'contributions' => $contribution !== null ? [$contribution] : null,
            'reversed_from_debt_id' => $reversedFromDebtId,
        ]);
    }

    /**
     * Find an open personal in-family running debt the payer owes the recipient, or create
     * one for $amount so a family transfer can reuse the debt-payment transaction pair.
     */
    public function findOrCreatePayableInterFamilyDebt(
        User $payer,
        User $recipient,
        float $amount,
        ?string $description = null,
    ): Debt {
        if ($payer->family_id === null || (int) $payer->family_id !== (int) $recipient->family_id) {
            throw new InvalidArgumentException('Recipient must be a family member.');
        }

        if ((int) $payer->id === (int) $recipient->id) {
            throw new InvalidArgumentException('You cannot send money to yourself.');
        }

        $amount = round($amount, 2);
        if ($amount < 0.01) {
            throw new InvalidArgumentException('Transfer amount must be at least 0.01.');
        }

        $existing = Debt::query()
            ->where('family_id', $payer->family_id)
            ->where('debtor_id', $payer->id)
            ->where('creditor_id', $recipient->id)
            ->where('is_pending_closeout', false)
            ->where('is_family_debt', false)
            ->whereNull('transaction_id')
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return Debt::query()->create([
            'family_id' => $payer->family_id,
            'debtor_id' => $payer->id,
            'creditor_id' => $recipient->id,
            'amount' => $amount,
            'balance' => $amount,
            'description' => $description ?: ('Sent to '.$recipient->name),
            'is_pending_closeout' => false,
            'is_family_debt' => false,
        ]);
    }

    /**
     * Pay a debt by creating corresponding transactions and updating the debt balance.
     *
     * @param  Debt  $debt  The debt record to pay
     * @param  float  $paymentAmount  The amount to pay (must be > 0 and <= balance)
     * @param  string  $description  Optional description for the transactions
     * @param  User  $payer  The user making the payment (must be the debtor)
     * @param  bool  $isCloseoutInitiated  Whether the payment was initiated from a month closeout
     * @param  string|null  $paymentDate  Optional explicit date for the payment transaction
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
        ?string $paymentDate = null,
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

        if ($debt->creditor_id === null && $paymentAmount > round((float) $debt->balance, 2)) {
            throw new InvalidArgumentException('Payment amount cannot exceed the remaining debt balance.');
        }

        DB::transaction(function () use ($debt, $paymentAmount, $description, $payer, $isCloseoutInitiated, $paymentDate, $splitWithUserId, $splitPercentage): void {
            $transactionDate = $paymentDate ?: Carbon::today()->toDateString();
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

            $previousBalance = round((float) $debt->balance, 2);
            $overpayment = round($paymentAmount - $previousBalance, 2);
            if ($overpayment > 0 && $debt->creditor_id !== null) {
                $debt->balance = '0.00';
                $debt->save();
                $this->applyInterFamilyPairNet(
                    (int) $debt->family_id,
                    (int) $debt->creditor_id,
                    (int) $debt->debtor_id,
                    $overpayment,
                    'Reversed from overpayment: '.($description ?: 'Debt payment'),
                    null,
                    (int) $debt->id,
                    $transactionDate,
                );
            } else {
                $debt->decrement('balance', $paymentAmount);
            }

            if ($creditorIncome) {
                $payerTransaction->forceFill(['mirror_transaction_id' => $creditorIncome->id])->save();
                $creditorIncome->forceFill(['mirror_transaction_id' => $payerTransaction->id])->save();
            }
        });
    }
}
