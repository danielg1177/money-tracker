<?php

namespace App\Http\Requests;

use App\Services\Closeout\CloseoutMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFamilyCloseoutSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user?->can_manage_family && (bool) $user?->family_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'closeout_mode' => ['required', Rule::in(CloseoutMode::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'closeout_mode.required' => 'A closeout mode is required.',
            'closeout_mode.in' => 'Closeout mode must be classic or family pooled.',
        ];
    }
}
