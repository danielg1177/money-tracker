<?php

namespace App\Http\Requests;

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
