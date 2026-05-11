<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyPlaidCalibrationRequest extends FormRequest
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
            'confirmed_pairs' => ['present', 'array'],
            'confirmed_pairs.*.plaid_transaction_id' => ['required', 'string'],
            'confirmed_pairs.*.ledger_transaction_id' => ['required', 'integer', 'exists:transactions,id'],
            'confirmed_pairs.*.category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('family_id', $user?->family_id ?? 0)
                ),
            ],
            'confirmed_pairs.*.type' => ['required', 'in:income,expense'],
            'confirmed_pairs.*.fund_id' => [
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
            'confirmed_pairs.*.advance_fund_id' => [
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
            'confirmed_pairs.*.is_non_necessity' => ['sometimes', 'boolean'],
            'import_as_new' => ['present', 'array'],
            'import_as_new.*' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $user = $this->user();
            if ($user === null || $user->family_id === null) {
                return;
            }

            foreach ($this->input('confirmed_pairs', []) as $i => $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $ledgerId = $pair['ledger_transaction_id'] ?? null;
                if ($ledgerId === null) {
                    continue;
                }
                $ledger = Transaction::query()->find($ledgerId);
                if ($ledger === null || (int) $ledger->family_id !== (int) $user->family_id) {
                    $v->errors()->add("confirmed_pairs.$i.ledger_transaction_id", 'The selected ledger transaction is not in your family.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $pairs = $this->input('confirmed_pairs', []);
        if (! is_array($pairs)) {
            return;
        }

        $merged = [];
        foreach ($pairs as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            if (($pair['type'] ?? null) === 'income') {
                $pair['advance_fund_id'] = null;
                $pair['is_non_necessity'] = false;
            }
            $merged[] = $pair;
        }
        $this->merge(['confirmed_pairs' => $merged]);
    }
}
