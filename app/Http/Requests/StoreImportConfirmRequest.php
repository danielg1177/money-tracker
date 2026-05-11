<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TransactionPayloadValidationRules;
use App\Models\PlaidPendingImport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportConfirmRequest extends FormRequest
{
    use TransactionPayloadValidationRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $pending = $this->route('pendingImport');
        if ($pending instanceof PlaidPendingImport) {
            $this->merge([
                'amount' => (float) $pending->amount,
                'transaction_date' => $pending->date->format('Y-m-d'),
            ]);
        }

        if ($this->input('type') === 'income') {
            $this->merge([
                'advance_fund_id' => null,
                'is_split' => false,
                'split_data' => null,
                'debt_id' => null,
                'is_non_necessity' => false,
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
            ]);
        }

        if ($this->filled('debt_id')) {
            $this->merge([
                'advance_fund_id' => null,
                'is_non_necessity' => false,
            ]);
        }

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

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        return array_merge([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('family_id', $user?->family_id ?? 0)
                ),
            ],
            'type' => ['required', 'in:income,expense'],
            'fund_id' => [
                'nullable',
                'integer',
                Rule::exists('funds', 'id')->where(function ($query) use ($user): void {
                    if ($user === null || $user->family_id === null) {
                        $query->whereRaw('1 = 0');

                        return;
                    }
                    $query->where('user_id', $user->id)
                        ->orWhere('family_id', $user->family_id);
                }),
            ],
            'description' => ['nullable', 'string', 'max:65535'],
        ], $this->sharedTransactionFieldRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge([
            'category_id.required' => 'A category is required.',
            'type.required' => 'Transaction type is required.',
        ], $this->sharedTransactionPayloadMessages());
    }
}
