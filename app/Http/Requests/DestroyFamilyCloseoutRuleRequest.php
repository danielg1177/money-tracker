<?php

namespace App\Http\Requests;

use App\Models\FamilyCloseoutRule;
use Illuminate\Foundation\Http\FormRequest;

class DestroyFamilyCloseoutRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $rule = $this->route('familyCloseoutRule');

        return (bool) $user?->can_manage_family
            && (bool) $user?->family_id
            && $rule instanceof FamilyCloseoutRule
            && (int) $rule->family_id === (int) $user->family_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
