<?php

namespace App\Http\Requests;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') === 'income') {
            $this->merge([
                'advance_fund_id' => null,
                'is_split' => false,
                'split_data' => null,
                'debt_id' => null,
                'income_debt_mode' => $this->input('income_debt_mode', 'none'),
            ]);
        }

        if ($this->input('type') !== 'income') {
            $this->merge([
                'income_debt_mode' => 'none',
                'income_existing_debt_id' => null,
                'income_new_is_family_debt' => false,
                'income_new_is_interfamily' => false,
                'income_new_creditor_id' => null,
                'income_new_creditor_name' => null,
                'income_new_description' => null,
            ]);
        }

        if ($this->filled('debt_id')) {
            $this->merge([
                'is_split' => false,
                'split_data' => null,
                'advance_fund_id' => null,
            ]);
        }

        if ($this->input('income_debt_mode') === 'existing') {
            $this->merge([
                'income_new_is_family_debt' => false,
                'income_new_is_interfamily' => false,
                'income_new_creditor_id' => null,
                'income_new_creditor_name' => null,
            ]);
        }

        if ($this->input('income_debt_mode') !== 'new') {
            $this->merge([
                'income_new_is_family_debt' => false,
                'income_new_is_interfamily' => false,
                'income_new_creditor_id' => null,
                'income_new_creditor_name' => null,
                'income_new_description' => null,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->input('type') !== 'expense') {
                return;
            }

            if (! $this->filled('debt_id')) {
                return;
            }

            $user = $this->user();
            if (! $user?->family_id) {
                return;
            }

            $amount = round((float) $this->input('amount'), 2);
            if ($amount <= 0) {
                return;
            }

            if ($this->boolean('is_split')) {
                $v->errors()->add('debt_id', 'A debt repayment cannot be combined with splitting the expense.');
            }

            $debt = Debt::query()
                ->where('family_id', $user->family_id)
                ->whereKey($this->input('debt_id'))
                ->first();

            if (! $debt) {
                $v->errors()->add('debt_id', 'The selected debt does not belong to your family.');

                return;
            }

            if ($debt->is_pending_closeout) {
                $v->errors()->add('debt_id', 'This debt is pending split closeout and cannot be paid this way.');
            }

            if ($amount > round((float) $debt->balance, 2)) {
                $v->errors()->add('amount', 'Payment amount cannot exceed the remaining debt balance.');
            }

            if ($debt->is_family_debt) {
                if ($user->family_id !== $debt->family_id) {
                    $v->errors()->add('debt_id', 'You cannot pay this debt.');
                }
            } elseif ($user->id !== $debt->debtor_id) {
                $v->errors()->add('debt_id', 'Only the debtor can record this repayment.');
            }

        });

        $validator->after(function (Validator $v): void {
            if ($this->input('type') !== 'income') {
                return;
            }

            $user = $this->user();
            if (! $user?->family_id) {
                return;
            }

            $mode = (string) $this->input('income_debt_mode', 'none');
            if ($mode === 'none') {
                return;
            }

            if ($mode === 'existing') {
                if (! $this->filled('income_existing_debt_id')) {
                    $v->errors()->add('income_existing_debt_id', 'Select an existing debt to attach this income to.');

                    return;
                }

                $debt = Debt::query()
                    ->where('family_id', $user->family_id)
                    ->whereKey($this->input('income_existing_debt_id'))
                    ->first();

                if (! $debt) {
                    $v->errors()->add('income_existing_debt_id', 'The selected debt does not belong to your family.');

                    return;
                }

                if ($debt->is_pending_closeout) {
                    $v->errors()->add('income_existing_debt_id', 'Pending split closeout debts cannot be increased this way.');
                }

                if ((int) $debt->debtor_id !== (int) $user->id) {
                    $v->errors()->add('income_existing_debt_id', 'You can only attach this income to debts where you are the debtor.');
                }

                return;
            }

            if ($mode !== 'new') {
                $v->errors()->add('income_debt_mode', 'Invalid income debt option.');

                return;
            }

            if ($this->boolean('income_new_is_interfamily')) {
                if (! $this->filled('income_new_creditor_id')) {
                    $v->errors()->add('income_new_creditor_id', 'Select a family member creditor.');

                    return;
                }

                $creditor = User::query()->find($this->input('income_new_creditor_id'));
                if (! $creditor || (int) $creditor->family_id !== (int) $user->family_id || (int) $creditor->id === (int) $user->id) {
                    $v->errors()->add('income_new_creditor_id', 'Creditor must be a different family member.');
                }

                return;
            }

            if (! $this->filled('income_new_creditor_name')) {
                $v->errors()->add('income_new_creditor_name', 'Creditor name is required when not selecting a family member.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'exists:categories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'in:income,expense'],
            'transaction_date' => ['required', 'date'],
            'is_split' => ['boolean'],
            'split_data' => ['exclude_if:is_split,false', 'required_if:is_split,true', 'array'],
            'split_data.*.user_id' => ['required_with:split_data', 'exists:users,id'],
            'split_data.*.share_percentage' => ['required_with:split_data', 'numeric', 'min:0', 'max:100'],
            'advance_fund_id' => ['nullable', 'exists:funds,id'],
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
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'type.required' => 'The transaction type is required.',
            'type.in' => 'The transaction type must be either income or expense.',
            'transaction_date.required' => 'The transaction date is required.',
            'transaction_date.date' => 'The transaction date must be a valid date.',
            'category_id.exists' => 'The selected category does not exist.',
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
        ];
    }
}
