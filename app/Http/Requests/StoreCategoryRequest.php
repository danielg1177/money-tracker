<?php

namespace App\Http\Requests;

use App\Models\FundRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('is_expense')) {
            $this->merge([
                'advance_fund_id' => null,
                'is_split_default' => false,
                'split_default' => null,
            ]);
        }

        // Clear is_non_necessity_default if not applicable
        if (! $this->boolean('is_expense') || ! $this->filled('advance_fund_id')) {
            $this->merge(['is_non_necessity_default' => false]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $income = $this->boolean('is_income');
            $expense = $this->boolean('is_expense');

            if ($income === $expense) {
                $v->errors()->add(
                    'is_income',
                    'A category must be either income or expense, not both and not neither.',
                );
            }
        });

        $validator->after(function (Validator $v): void {
            if (! $this->boolean('is_non_necessity_default')) {
                return;
            }

            if (! $this->boolean('is_expense') || ! $this->filled('advance_fund_id')) {
                return;
            }

            $advanceFundId = (int) $this->input('advance_fund_id');
            $hasMatchingRule = FundRule::query()
                ->where('user_id', auth()->id())
                ->where('is_active', true)
                ->where('destination_type', 'fund')
                ->where('destination_id', $advanceFundId)
                ->where('allocation_type', 'percentage')
                ->where('allocation_base', 'remaining')
                ->exists();

            if (! $hasMatchingRule) {
                $v->errors()->add('is_non_necessity_default', 'Non-necessity default requires an active percentage-of-remaining closeout rule targeting the selected advance fund.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'family_id' => ['nullable', 'exists:families,id'],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_income' => ['boolean'],
            'is_expense' => ['boolean'],
            'is_split_default' => ['boolean'],
            'is_non_necessity_default' => ['boolean'],
            'split_default' => ['nullable', 'array'],
            'advance_fund_id' => ['nullable', 'exists:funds,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'family_id.required' => 'The family ID is required.',
            'family_id.exists' => 'The selected family does not exist.',
            'name.required' => 'The category name is required.',
            'name.string' => 'The category name must be a string.',
            'name.max' => 'The category name cannot exceed 255 characters.',
            'icon.string' => 'The icon must be a string.',
            'icon.max' => 'The icon cannot exceed 100 characters.',
            'split_default.array' => 'Split default must be an array.',
        ];
    }
}
