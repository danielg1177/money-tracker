<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportConfirmRequest extends FormRequest
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
            'advance_fund_id' => [
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
            'is_non_necessity' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') === 'income') {
            $this->merge([
                'advance_fund_id' => null,
                'is_non_necessity' => false,
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.required' => 'A category is required.',
            'type.required' => 'Transaction type is required.',
        ];
    }
}
