<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TransactionPayloadValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    use TransactionPayloadValidationRules;

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
                'income_new_interest_enabled' => false,
                'income_new_interest_rate' => null,
                'is_repayment_mode' => false,
            ]);
        }

        if ($this->filled('debt_id')) {
            $this->merge([
                'advance_fund_id' => null,
            ]);
        }

        // Force is_non_necessity off if the transaction cannot qualify
        if (
            $this->input('type') !== 'expense'
            || ! $this->filled('advance_fund_id')
            || $this->boolean('is_split')
            || $this->filled('debt_id')
        ) {
            $this->merge(['is_non_necessity' => false]);
        }

        if ($this->input('income_debt_mode') === 'existing') {
            $this->merge([
                'income_new_is_family_debt' => false,
                'income_new_is_interfamily' => false,
                'income_new_creditor_id' => null,
                'income_new_creditor_name' => null,
                'income_new_interest_enabled' => false,
                'income_new_interest_rate' => null,
            ]);
        }

        if ($this->input('income_debt_mode') !== 'new') {
            $this->merge([
                'income_new_is_family_debt' => false,
                'income_new_is_interfamily' => false,
                'income_new_creditor_id' => null,
                'income_new_creditor_name' => null,
                'income_new_description' => null,
                'income_new_interest_enabled' => false,
                'income_new_interest_rate' => null,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $this->configureTransactionPayloadValidator($validator);
    }

    public function rules(): array
    {
        return array_merge([
            'category_id' => ['nullable', 'exists:categories,id'],
            'type' => ['required', 'in:income,expense'],
            'description' => ['nullable', 'string', 'max:65535'],
        ], $this->sharedTransactionFieldRules());
    }

    public function messages(): array
    {
        return array_merge([
            'category_id.exists' => 'The selected category does not exist.',
        ], $this->sharedTransactionPayloadMessages());
    }
}
