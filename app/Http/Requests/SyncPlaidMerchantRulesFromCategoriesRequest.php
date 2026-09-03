<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncPlaidMerchantRulesFromCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->family_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
