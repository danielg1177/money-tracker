<?php

namespace App\Http\Requests;

use App\Models\FamilyCloseoutRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFamilyCloseoutRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can_manage_family && (bool) $this->user()?->family_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'order' => ['required', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'stage' => ['required', Rule::in(FamilyCloseoutRule::stages())],
            'allocation_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'destination_type' => ['required', Rule::in(['fund', 'debt', 'title'])],
            'destination_id' => ['nullable', 'integer'],
            'destination_title' => ['nullable', 'string', 'max:255', 'required_if:destination_type,title'],
            'closeout_expense_category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(function ($query): void {
                    $query->where('family_id', $this->user()->family_id)
                        ->where('is_expense', true);
                }),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The rule name is required.',
            'stage.in' => 'Stage must be surplus or remaining after charity.',
            'destination_title.required_if' => 'A title is required when the destination is a titled saving.',
        ];
    }
}
