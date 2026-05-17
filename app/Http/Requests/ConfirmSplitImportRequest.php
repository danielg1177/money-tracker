<?php

namespace App\Http\Requests;

use App\Models\PlaidPendingImport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmSplitImportRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.type' => ['required', 'string', 'in:expense,income'],
            'lines.*.category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('family_id', $user?->family_id ?? 0)
                ),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:65535'],
            'lines.*.fund_id' => [
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'At least two split lines are required.',
            'lines.min' => 'At least two split lines are required.',
            'lines.*.amount.required' => 'Each line must have an amount.',
            'lines.*.amount.min' => 'Each line amount must be at least $0.01.',
            'lines.*.type.required' => 'Each line must have a type.',
            'lines.*.category_id.required' => 'Each line must have a category.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var PlaidPendingImport|null $pendingImport */
            $pendingImport = $this->route('pendingImport');
            if ($pendingImport === null) {
                return;
            }

            $lines = $this->input('lines', []);
            if (! is_array($lines)) {
                return;
            }

            $lineSum = collect($lines)->sum(fn ($line) => (float) ($line['amount'] ?? 0));
            $importTotal = (float) $pendingImport->amount;
            if (abs($lineSum - $importTotal) > 0.01) {
                $formatted = number_format($importTotal, 2);
                $v->errors()->add('lines', "Split line amounts must sum to the total import amount of \${$formatted}.");
            }
        });
    }
}
