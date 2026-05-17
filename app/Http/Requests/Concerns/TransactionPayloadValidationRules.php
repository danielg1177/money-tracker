<?php

namespace App\Http\Requests\Concerns;

use App\Models\Debt;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait TransactionPayloadValidationRules
{
    /**
     * Validation rules for transaction-like payloads (amount, splits, debt, income-debt, advance fund).
     * Callers merge their own `category_id` / `fund_id` rules.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function sharedTransactionFieldRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
            'is_split' => ['boolean'],
            'split_data' => ['exclude_if:is_split,false', 'required_if:is_split,true', 'array'],
            'split_data.*.user_id' => ['required_with:split_data', 'exists:users,id'],
            'split_data.*.share_percentage' => ['required_with:split_data', 'numeric', 'min:0', 'max:100'],
            'advance_fund_id' => ['nullable', 'exists:funds,id'],
            'is_non_necessity' => ['boolean'],
            'debt_id' => [
                'nullable',
                'integer',
                Rule::exists('debts', 'id')->where(
                    fn ($query) => $query->where('family_id', $this->user()?->family_id ?? 0)
                ),
            ],
            'income_debt_mode' => ['nullable', 'in:none,existing,new'],
            'income_existing_debt_id' => ['nullable', 'integer', 'exists:debts,id'],
            'income_new_is_family_debt' => ['nullable', 'boolean'],
            'income_new_is_interfamily' => ['nullable', 'boolean'],
            'income_new_creditor_id' => ['nullable', 'integer', 'exists:users,id'],
            'income_new_creditor_name' => ['nullable', 'string', 'max:255'],
            'income_new_description' => ['nullable', 'string'],
            'income_new_interest_enabled' => ['nullable', 'boolean'],
            'income_new_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_repayment_mode' => ['boolean'],
            'repayment_for_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'repayment_links' => ['nullable', 'array'],
            'repayment_links.*.transaction_id' => ['required_with:repayment_links', 'integer', Rule::exists('transactions', 'id')],
            'repayment_links.*.amount' => ['required_with:repayment_links', 'numeric', 'min:0.01'],
        ];
    }

    protected function configureTransactionPayloadValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateTransactionPayloadData($v, $this->all(), '');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validateTransactionPayloadData(Validator $validator, array $data, string $errorKeyPrefix = ''): void
    {
        $field = fn (string $key): string => $errorKeyPrefix === '' ? $key : "{$errorKeyPrefix}.{$key}";
        $value = fn (string $key, mixed $default = null): mixed => $data[$key] ?? $default;
        $filled = fn (string $key): bool => array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '';
        $boolean = fn (string $key): bool => filter_var($value($key, false), FILTER_VALIDATE_BOOLEAN);

        if (($value('type') ?? '') === 'expense' && $filled('debt_id')) {
            $user = $this->user();
            if ($user?->family_id) {
                $amount = round((float) $value('amount', 0), 2);
                if ($amount > 0) {
                    $debt = Debt::query()
                        ->where('family_id', $user->family_id)
                        ->whereKey($value('debt_id'))
                        ->first();

                    if (! $debt) {
                        $validator->errors()->add($field('debt_id'), 'The selected debt does not belong to your family.');
                    } else {
                        if ($debt->is_pending_closeout) {
                            $validator->errors()->add($field('debt_id'), 'This debt is pending split closeout and cannot be paid this way.');
                        }

                        if ($amount > round((float) $debt->balance, 2)) {
                            $validator->errors()->add($field('amount'), 'Payment amount cannot exceed the remaining debt balance.');
                        }

                        if ($debt->is_family_debt) {
                            if ($user->family_id !== $debt->family_id) {
                                $validator->errors()->add($field('debt_id'), 'You cannot pay this debt.');
                            }
                        } elseif ($user->id !== $debt->debtor_id) {
                            $validator->errors()->add($field('debt_id'), 'Only the debtor can record this repayment.');
                        }
                    }
                }
            }
        }

        if (($value('type') ?? '') === 'income') {
            $user = $this->user();
            if ($user?->family_id) {
                $mode = (string) $value('income_debt_mode', 'none');
                if ($mode === 'existing') {
                    if (! $filled('income_existing_debt_id')) {
                        $validator->errors()->add($field('income_existing_debt_id'), 'Select an existing debt to attach this income to.');
                    } else {
                        $debt = Debt::query()
                            ->where('family_id', $user->family_id)
                            ->whereKey($value('income_existing_debt_id'))
                            ->first();

                        if (! $debt) {
                            $validator->errors()->add($field('income_existing_debt_id'), 'The selected debt does not belong to your family.');
                        } else {
                            if ($debt->is_pending_closeout) {
                                $validator->errors()->add($field('income_existing_debt_id'), 'Pending split closeout debts cannot be increased this way.');
                            }

                            if ((int) $debt->debtor_id !== (int) $user->id) {
                                $validator->errors()->add($field('income_existing_debt_id'), 'You can only attach this income to debts where you are the debtor.');
                            }
                        }
                    }
                } elseif ($mode === 'new') {
                    if ($boolean('income_new_is_interfamily')) {
                        if (! $filled('income_new_creditor_id')) {
                            $validator->errors()->add($field('income_new_creditor_id'), 'Select a family member creditor.');
                        } else {
                            $creditor = User::query()->find($value('income_new_creditor_id'));
                            if (! $creditor || (int) $creditor->family_id !== (int) $user->family_id || (int) $creditor->id === (int) $user->id) {
                                $validator->errors()->add($field('income_new_creditor_id'), 'Creditor must be a different family member.');
                            }
                        }
                    } elseif (! $filled('income_new_creditor_name')) {
                        $validator->errors()->add($field('income_new_creditor_name'), 'Creditor name is required when not selecting a family member.');
                    }

                    if ($boolean('income_new_interest_enabled')) {
                        $interestRate = $value('income_new_interest_rate');
                        if (! is_numeric($interestRate) || (float) $interestRate < 0 || (float) $interestRate > 100) {
                            $validator->errors()->add($field('income_new_interest_rate'), 'Interest rate must be between 0 and 100.');
                        }
                    }
                } elseif ($mode !== 'none') {
                    $validator->errors()->add($field('income_debt_mode'), 'Invalid income debt option.');
                }

                $isRepaymentMode = filter_var($value('is_repayment_mode', false), FILTER_VALIDATE_BOOLEAN);
                if ($isRepaymentMode) {
                    $repaidUserId = $value('repayment_for_user_id');
                    $repaymentLinks = $value('repayment_links', []);
                    if (! $filled('repayment_for_user_id')) {
                        $validator->errors()->add($field('repayment_for_user_id'), 'Select the family member who is repaying you.');
                    } else {
                        $repaidUser = User::query()->find($repaidUserId);
                        if (! $repaidUser || (int) $repaidUser->family_id !== (int) ($user?->family_id ?? 0)) {
                            $validator->errors()->add($field('repayment_for_user_id'), 'The selected user must be a family member.');
                        } elseif ((int) $repaidUserId === (int) ($user?->id ?? 0)) {
                            $validator->errors()->add($field('repayment_for_user_id'), 'You cannot select yourself as the repaying user.');
                        }
                    }
                    if (empty($repaymentLinks)) {
                        $validator->errors()->add($field('repayment_links'), 'Select at least one expense transaction to link this repayment to.');
                    } else {
                        $linkedAmount = collect($repaymentLinks)->sum(fn ($l) => (float) ($l['amount'] ?? 0));
                        $incomeAmount = round((float) $value('amount', 0), 2);
                        if (abs($linkedAmount - $incomeAmount) > 0.01) {
                            $validator->errors()->add($field('repayment_links'), 'The sum of repayment amounts must equal the income amount.');
                        }
                        foreach ($repaymentLinks as $i => $link) {
                            $txId = $link['transaction_id'] ?? null;
                            if ($txId) {
                                $repaidTx = Transaction::query()
                                    ->where('family_id', $user?->family_id ?? 0)
                                    ->where('user_id', $user?->id ?? 0)
                                    ->where('type', 'expense')
                                    ->where('is_repaid', false)
                                    ->find($txId);
                                if (! $repaidTx) {
                                    $validator->errors()->add($field("repayment_links.{$i}.transaction_id"), 'This expense transaction is invalid or has already been repaid.');
                                }
                            }
                        }
                    }
                }
            }
        }

        if (($value('type') ?? '') === 'expense' && $boolean('is_non_necessity')) {
            $user = $this->user();
            $advanceFundId = (int) $value('advance_fund_id', 0);
            $hasEligibleRule = $user !== null
                && FundRule::query()
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->where('destination_type', 'fund')
                    ->where('destination_id', $advanceFundId)
                    ->where('allocation_type', 'percentage')
                    ->where('allocation_base', 'remaining')
                    ->exists();

            if (
                ! $filled('advance_fund_id')
                || $boolean('is_split')
                || ! $hasEligibleRule
            ) {
                $validator->errors()->add($field('is_non_necessity'), 'Non-necessity is only allowed for non-split expenses with an advance fund that has an active percentage-of-remaining closeout rule targeting that fund.');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function sharedTransactionPayloadMessages(): array
    {
        return [
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'type.required' => 'The transaction type is required.',
            'type.in' => 'The transaction type must be either income or expense.',
            'transaction_date.required' => 'The transaction date is required.',
            'transaction_date.date' => 'The transaction date must be a valid date.',
            'split_data.required_if' => 'Split data is required when split is enabled.',
            'split_data.array' => 'Split data must be an array.',
            'split_data.*.user_id.required' => 'User ID is required for each split.',
            'split_data.*.user_id.exists' => 'One or more users do not exist.',
            'split_data.*.share_percentage.required' => 'Share percentage is required for each split.',
            'split_data.*.share_percentage.numeric' => 'Share percentage must be a valid number.',
            'split_data.*.share_percentage.min' => 'Share percentage must be at least 0.',
            'split_data.*.share_percentage.max' => 'Share percentage cannot exceed 100.',
            'advance_fund_id.exists' => 'The selected advance fund does not exist.',
            'debt_id.exists' => 'The selected debt is invalid.',
            'is_non_necessity' => 'Non-necessity requires an advance fund with a matching closeout rule.',
        ];
    }
}
