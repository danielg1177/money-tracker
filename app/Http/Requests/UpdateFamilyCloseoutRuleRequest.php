<?php

namespace App\Http\Requests;

use App\Models\FamilyCloseoutRule;

class UpdateFamilyCloseoutRuleRequest extends StoreFamilyCloseoutRuleRequest
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
}
