<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        ];
    }
}
