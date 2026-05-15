<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SweepFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'A sweep amount is required.',
            'amount.numeric' => 'The sweep amount must be a number.',
            'amount.min' => 'The sweep amount must be at least $0.01.',
        ];
    }
}
