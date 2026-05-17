<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TransactionPayloadValidationRules;
use App\Models\PlaidPendingImport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmSplitImportRequest extends FormRequest
{
    use TransactionPayloadValidationRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines', []);
        if (! is_array($lines)) {
            return;
        }

        $normalized = [];
        foreach ($lines as $line) {
            $normalized[] = is_array($line) ? $this->normalizeSplitLine($line) : $line;
        }

        $this->merge(['lines' => $normalized]);
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

            $lineSum = collect($lines)->sum(fn ($line) => (float) (is_array($line) ? ($line['amount'] ?? 0) : 0));
            $importTotal = (float) $pendingImport->amount;
            if (abs($lineSum - $importTotal) > 0.01) {
                $formatted = number_format($importTotal, 2);
                $v->errors()->add('lines', "Split line amounts must sum to the total import amount of \${$formatted}.");
            }

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $this->validateTransactionPayloadData($v, $line, "lines.{$index}");
            }
        });
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        $rules = [
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

        foreach ($this->sharedTransactionFieldRules() as $key => $fieldRules) {
            if (in_array($key, ['amount', 'transaction_date'], true)) {
                continue;
            }

            $rules["lines.*.{$key}"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'lines.required' => 'At least two split lines are required.',
            'lines.min' => 'At least two split lines are required.',
            'lines.*.amount.required' => 'Each line must have an amount.',
            'lines.*.amount.min' => 'Each line amount must be at least $0.01.',
            'lines.*.type.required' => 'Each line must have a type.',
            'lines.*.category_id.required' => 'Each line must have a category.',
        ];

        foreach ($this->sharedTransactionPayloadMessages() as $key => $message) {
            $messages["lines.*.{$key}"] = $message;
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizeSplitLine(array $line): array
    {
        if (($line['type'] ?? '') === 'income') {
            $line['advance_fund_id'] = null;
            $line['is_split'] = false;
            $line['split_data'] = null;
            $line['debt_id'] = null;
            $line['is_non_necessity'] = false;
            $line['income_debt_mode'] = $line['income_debt_mode'] ?? 'none';
        }

        if (($line['type'] ?? '') !== 'income') {
            $line['income_debt_mode'] = 'none';
            $line['income_existing_debt_id'] = null;
            $line['income_new_is_family_debt'] = false;
            $line['income_new_is_interfamily'] = false;
            $line['income_new_creditor_id'] = null;
            $line['income_new_creditor_name'] = null;
            $line['income_new_description'] = null;
            $line['income_new_interest_enabled'] = false;
            $line['income_new_interest_rate'] = null;
            $line['is_repayment_mode'] = false;
        }

        if (! empty($line['debt_id'])) {
            $line['advance_fund_id'] = null;
            $line['is_non_necessity'] = false;
        }

        if (
            ($line['type'] ?? '') !== 'expense'
            || empty($line['advance_fund_id'])
            || ! empty($line['is_split'])
            || ! empty($line['debt_id'])
        ) {
            $line['is_non_necessity'] = false;
        }

        if (($line['income_debt_mode'] ?? 'none') === 'existing') {
            $line['income_new_is_family_debt'] = false;
            $line['income_new_is_interfamily'] = false;
            $line['income_new_creditor_id'] = null;
            $line['income_new_creditor_name'] = null;
            $line['income_new_interest_enabled'] = false;
            $line['income_new_interest_rate'] = null;
        }

        if (($line['income_debt_mode'] ?? 'none') !== 'new') {
            $line['income_new_is_family_debt'] = false;
            $line['income_new_is_interfamily'] = false;
            $line['income_new_creditor_id'] = null;
            $line['income_new_creditor_name'] = null;
            $line['income_new_description'] = null;
            $line['income_new_interest_enabled'] = false;
            $line['income_new_interest_rate'] = null;
        }

        return $line;
    }
}
