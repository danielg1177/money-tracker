<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\FundRule;
use App\Services\SplitCalculator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDebtPaymentBenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('is_split') || ! $this->filled('advance_fund_id')) {
            $this->merge(['exclude_from_expense_basis' => false]);
        }

        if (! $this->boolean('is_split')) {
            $this->merge(['split_data' => null]);
        }

        if ($this->boolean('is_split')) {
            $this->merge([
                'advance_fund_id' => null,
                'exclude_from_expense_basis' => false,
            ]);
        }
    }

    public function rules(): array
    {
        $familyId = $this->user()?->family_id ?? 0;

        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('family_id', $familyId)->where('is_expense', true)
                ),
            ],
            'description' => ['nullable', 'string', 'max:65535'],
            'is_split' => ['boolean'],
            'split_data' => ['exclude_if:is_split,false', 'required_if:is_split,true', 'array'],
            'split_data.*.user_id' => ['required_with:split_data', 'exists:users,id'],
            'split_data.*.share_percentage' => ['required_with:split_data', 'numeric', 'min:0', 'max:100'],
            'advance_fund_id' => ['nullable', 'exists:funds,id'],
            'exclude_from_expense_basis' => ['boolean'],
            'is_necessity' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('is_split')) {
                $splitData = $this->input('split_data', []);
                if (! is_array($splitData) || ! SplitCalculator::validate($splitData)) {
                    $v->errors()->add('split_data', 'Split percentages must sum to 100%.');
                }
            }

            if ($this->boolean('exclude_from_expense_basis')) {
                $user = $this->user();
                $advanceFundId = (int) $this->input('advance_fund_id', 0);
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
                    ! $this->filled('advance_fund_id')
                    || $this->boolean('is_split')
                    || ! $hasEligibleRule
                ) {
                    $v->errors()->add(
                        'exclude_from_expense_basis',
                        'Exclude from remaining is only allowed for non-split expenses with an advance fund that has an active percentage-of-remaining closeout rule targeting that fund.'
                    );
                }
            }

            if ($this->filled('category_id')) {
                $category = Category::query()->find($this->input('category_id'));
                if ($category && ! $category->is_expense) {
                    $v->errors()->add('category_id', 'The selected category must be an expense category.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Please select an expense category.',
            'category_id.exists' => 'The selected category does not exist or is not an expense category.',
            'split_data.required_if' => 'Split data is required when split is enabled.',
            'advance_fund_id.exists' => 'The selected advance fund does not exist.',
        ];
    }
}
