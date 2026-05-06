<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBankBalanceRequest;
use App\Models\CloseoutTitleSaving;
use App\Models\FundRule;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BankBalanceController extends Controller
{
    /**
     * Return current bank balance tracking state for the authenticated user.
     */
    public function show(): JsonResponse
    {
        $user = auth()->user();

        if (! $user->bank_balance_enabled) {
            return response()->json([
                'enabled' => false,
                'bank_balance' => null,
                'bank_balance_set_at' => null,
                'computed_balance' => null,
                'delta' => null,
            ]);
        }

        if (! $user->bank_balance_set_at) {
            return response()->json([
                'enabled' => true,
                'bank_balance' => null,
                'bank_balance_set_at' => null,
                'computed_balance' => null,
                'delta' => null,
            ]);
        }

        $setAt = $user->bank_balance_set_at->toDateString();

        $incomeTotal = (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->where('is_closeout_initiated', false)
            ->whereDate('transaction_date', '>=', $setAt)
            ->sum('amount');

        $expenseTotal = (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('is_closeout_initiated', false)
            ->whereDate('transaction_date', '>=', $setAt)
            ->sum('amount');

        $completedTitleTotal = (float) CloseoutTitleSaving::query()
            ->where('user_id', $user->id)
            ->where('is_completed', true)
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', $setAt)
            ->sum('amount');

        $computedBalance = (float) $user->bank_balance + $incomeTotal - $expenseTotal - $completedTitleTotal;

        return response()->json([
            'enabled' => true,
            'bank_balance' => (float) $user->bank_balance,
            'bank_balance_set_at' => $setAt,
            'computed_balance' => round($computedBalance, 2),
            'delta' => [
                'income' => round($incomeTotal, 2),
                'expense' => round($expenseTotal, 2),
                'title_savings_completed' => round($completedTitleTotal, 2),
            ],
        ]);
    }

    /**
     * Update bank balance settings and return refreshed computed state.
     */
    public function update(UpdateBankBalanceRequest $request): JsonResponse
    {
        $user = auth()->user();
        $data = [];

        if ($request->has('bank_balance_enabled')) {
            $data['bank_balance_enabled'] = $request->boolean('bank_balance_enabled');
        }

        if ($request->has('bank_balance') && $request->input('bank_balance') !== null) {
            $data['bank_balance'] = round((float) $request->input('bank_balance'), 2);
            $data['bank_balance_set_at'] = now()->toDateString();
            $data['bank_balance_enabled'] = true;
        }

        $user->update($data);
        $user->refresh();

        return $this->show();
    }

    /**
     * Mark a title saving record as completed.
     */
    public function completeTitleSaving(int $id): JsonResponse
    {
        $user = auth()->user();

        $titleSaving = DB::transaction(function () use ($id, $user): CloseoutTitleSaving {
            $titleSaving = CloseoutTitleSaving::query()
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($titleSaving->completion_transaction_id) {
                $titleSaving->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);

                return $titleSaving;
            }

            $rule = $titleSaving->rule_id ? FundRule::query()->find($titleSaving->rule_id) : null;

            $transaction = Transaction::query()->create([
                'family_id' => $user->family_id,
                'user_id' => $user->id,
                'category_id' => $rule?->closeout_expense_category_id,
                'type' => 'expense',
                'amount' => (float) $titleSaving->amount,
                'description' => "Completed title saving: {$titleSaving->title}",
                'transaction_date' => now()->toDateString(),
                'is_debt_payment' => false,
                'is_closeout_initiated' => true,
                'is_split' => false,
                'split_data' => null,
            ]);

            $titleSaving->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completion_transaction_id' => $transaction->id,
            ]);

            return $titleSaving;
        });

        return response()->json($titleSaving->fresh('completionTransaction'));
    }

    /**
     * Reverse completion state for a title saving record.
     */
    public function incompleteTitleSaving(int $id): JsonResponse
    {
        $user = auth()->user();

        $titleSaving = DB::transaction(function () use ($id, $user): CloseoutTitleSaving {
            $titleSaving = CloseoutTitleSaving::query()
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($titleSaving->completion_transaction_id) {
                Transaction::query()
                    ->where('id', $titleSaving->completion_transaction_id)
                    ->where('user_id', $user->id)
                    ->where('is_closeout_initiated', true)
                    ->delete();
            }

            $titleSaving->update([
                'is_completed' => false,
                'completed_at' => null,
                'completion_transaction_id' => null,
            ]);

            return $titleSaving;
        });

        return response()->json($titleSaving->fresh());
    }
}
