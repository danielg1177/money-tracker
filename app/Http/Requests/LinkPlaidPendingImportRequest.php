<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkPlaidPendingImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'transaction_id' => [
                'required',
                'integer',
                Rule::exists('transactions', 'id')->where(
                    fn ($query) => $query
                        ->where('family_id', $user?->family_id ?? 0)
                        ->where('user_id', $user?->id ?? 0)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transaction_id.required' => 'Choose a transaction to link.',
        ];
    }
}
