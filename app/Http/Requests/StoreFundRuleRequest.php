<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFundRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fund_id' => ['required', 'exists:funds,id'],
            'name' => ['required', 'string', 'max:255'],
            'order' => ['required', 'integer', 'min:0'],
            'allocation_type' => ['required', 'in:percentage,fixed'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'allocation_base' => ['required', 'in:gross_income,net_income,remaining'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'fund_id.required' => 'The fund ID is required.',
            'fund_id.exists' => 'The selected fund does not exist.',
            'name.required' => 'The rule name is required.',
            'name.string' => 'The rule name must be a string.',
            'name.max' => 'The rule name cannot exceed 255 characters.',
            'order.required' => 'The rule order is required.',
            'order.integer' => 'The rule order must be an integer.',
            'order.min' => 'The rule order must be at least 0.',
            'allocation_type.required' => 'The allocation type is required.',
            'allocation_type.in' => 'The allocation type must be either percentage or fixed.',
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'allocation_base.required' => 'The allocation base is required.',
            'allocation_base.in' => 'The allocation base must be gross income, net income, or remaining.',
        ];
    }
}
