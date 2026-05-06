<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'debt_id' => ['required', 'exists:debts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string'],
            'transaction_date' => ['nullable', 'date'],
            'split_with_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'split_percentage' => ['nullable', 'numeric', 'min:1', 'max:99'],
        ];
    }

    public function messages(): array
    {
        return [
            'debt_id.required' => 'The debt ID is required.',
            'debt_id.exists' => 'The selected debt does not exist.',
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'transaction_date.date' => 'The payment date must be a valid date.',
            'split_with_user_id.exists' => 'The selected user does not exist.',
            'split_percentage.min' => 'Split percentage must be at least 1.',
            'split_percentage.max' => 'Split percentage must be at most 99.',
        ];
    }
}
