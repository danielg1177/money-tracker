<?php

namespace App\Services\Closeout;

use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class CloseoutAllocationWriter
{
    public function apply(CloseoutAllocationCommand $command, User $user, int $year, int $month, float $amount): float
    {
        return match ($command->destinationType) {
            'fund' => $this->allocateToFund($command, $user, $year, $month, $amount),
            'debt' => $this->allocateToDebt($command, $user, $year, $month, $amount),
            'title' => $this->allocateToTitle($command, $user, $year, $month, $amount),
            default => 0.0,
        };
    }

    public function resolveCloseoutTransactionDate(int $year, int $month): string
    {
        $now = now();

        if ((int) $now->year === $year && (int) $now->month === $month) {
            return $now->toDateString();
        }

        return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
    }

    private function allocateToFund(CloseoutAllocationCommand $command, User $user, int $year, int $month, float $amount): float
    {
        $fund = Fund::query()->findOrFail($command->destinationId);
        $fund->increment('balance', $amount);

        $transaction = Transaction::query()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $command->closeoutExpenseCategoryId,
            'type' => 'expense',
            'amount' => $amount,
            'description' => "Closeout transfer to fund: {$fund->name}",
            'transaction_date' => $this->resolveCloseoutTransactionDate($year, $month),
            'is_debt_payment' => false,
            'is_closeout_initiated' => true,
            'closeout_scope' => $command->scope,
            'is_split' => false,
            'split_data' => null,
        ]);

        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'closeout_allocation',
            'amount' => $amount,
            'transaction_id' => $transaction->id,
            'description' => sprintf('Closeout rule: %s (%04d-%02d)', $command->name, $year, $month),
        ]);

        return $amount;
    }

    private function allocateToDebt(CloseoutAllocationCommand $command, User $user, int $year, int $month, float $amount): float
    {
        $debt = Debt::query()
            ->where('id', $command->destinationId)
            ->where('family_id', $user->family_id)
            ->first();

        if ($debt && $debt->balance > 0) {
            $payAmount = min($amount, (float) $debt->balance);
            $debt->decrement('balance', $payAmount);

            $debtLabel = $debt->creditor_name ?? $debt->creditor?->name ?? 'Unknown';

            Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'category_id' => $command->closeoutExpenseCategoryId,
                'type' => 'expense',
                'amount' => $payAmount,
                'description' => "Debt Payment: {$debtLabel}",
                'transaction_date' => $this->resolveCloseoutTransactionDate($year, $month),
                'is_debt_payment' => true,
                'debt_id' => $debt->id,
                'paid_by_user_id' => $user->id,
                'is_closeout_initiated' => true,
                'closeout_scope' => $command->scope,
            ]);

            return $payAmount;
        }

        return 0;
    }

    private function allocateToTitle(CloseoutAllocationCommand $command, User $user, int $year, int $month, float $amount): float
    {
        $titleSaving = CloseoutTitleSaving::query()->firstOrNew([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'year' => $year,
            'month' => $month,
            'title' => $command->destinationTitle,
        ]);

        $titleSaving->amount = ($titleSaving->amount ?? 0) + $amount;

        if (! $titleSaving->exists) {
            $titleSaving->rule_id = $command->scope === CloseoutScope::User ? $command->ruleId : null;
        }

        $titleSaving->save();

        return $amount;
    }
}
