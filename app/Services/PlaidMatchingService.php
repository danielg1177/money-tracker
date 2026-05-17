<?php

namespace App\Services;

use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\TransactionRepaymentLink;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class PlaidMatchingService
{
    /**
     * Find best matching existing ledger row for a Plaid transaction payload.
     *
     * Plaid: positive amount = expense, negative = income. Ledger uses positive `amount` with `type`.
     *
     * @param  array<string, mixed>  $plaidRow
     */
    public function findLedgerMatch(array $plaidRow, int $familyId): ?Transaction
    {
        $resolved = $this->findLedgerMatchWithScore($plaidRow, $familyId);

        return $resolved !== null ? $resolved['transaction'] : null;
    }

    /**
     * Same matching rules as {@see findLedgerMatch}, including the ≥ 0.3 score threshold.
     *
     * @param  array<string, mixed>  $plaidRow
     * @return array{transaction: Transaction, score: float}|null
     */
    public function findLedgerMatchWithScore(array $plaidRow, int $familyId): ?array
    {
        $resolved = $this->resolveLedgerMatch($plaidRow, $familyId);
        if ($resolved === null || $resolved['score'] < 0.3) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $plaidRow
     * @return array{transaction: Transaction, score: float}|null
     */
    private function resolveLedgerMatch(array $plaidRow, int $familyId): ?array
    {
        $plaidAmount = (float) data_get($plaidRow, 'amount', 0);

        if ($plaidAmount > 0) {
            $expectedType = 'expense';
            $ledgerAmount = $plaidAmount;
        } elseif ($plaidAmount < 0) {
            $expectedType = 'income';
            $ledgerAmount = abs($plaidAmount);
        } else {
            return null;
        }

        $dateStr = data_get($plaidRow, 'date') ?? data_get($plaidRow, 'authorized_date');
        if (! is_string($dateStr) || $dateStr === '') {
            return null;
        }

        try {
            $center = Carbon::parse($dateStr)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $merchantRaw = (string) (data_get($plaidRow, 'merchant_name') ?? data_get($plaidRow, 'name') ?? '');
        $merchantLower = mb_strtolower(trim($merchantRaw));

        $candidates = Transaction::query()
            ->where('family_id', $familyId)
            ->whereNull('plaid_transaction_id')
            ->where('type', $expectedType)
            ->whereBetween('transaction_date', [
                $center->copy()->subDay()->toDateString(),
                $center->copy()->addDay()->toDateString(),
            ])
            ->whereBetween('amount', [
                $ledgerAmount - 0.01,
                $ledgerAmount + 0.01,
            ])
            ->get();

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $transaction) {
            $score = $this->merchantSimilarityScore($merchantLower, (string) ($transaction->description ?? ''));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $transaction;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'transaction' => $best,
            'score' => $bestScore,
        ];
    }

    /**
     * Find a group of is_repayment_mirror expense transactions for the given user
     * whose amounts sum to the given Plaid expense amount (±$0.01).
     *
     * @param  array<string, mixed>  $plaidRow
     * @return array{repayment_transaction_id: int, mirror_transactions: Collection<int, Transaction>, total: float}|null
     */
    public function findRepaymentGroupMatch(array $plaidRow, int $userId): ?array
    {
        $plaidAmount = (float) data_get($plaidRow, 'amount', 0);
        if ($plaidAmount <= 0) {
            return null;
        }

        $dateStr = data_get($plaidRow, 'date') ?? data_get($plaidRow, 'authorized_date');
        if (! is_string($dateStr) || $dateStr === '') {
            return null;
        }

        try {
            $center = Carbon::parse($dateStr)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $mirrorExpenses = Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->where('is_repayment_mirror', true)
            ->whereNull('plaid_transaction_id')
            ->whereBetween('transaction_date', [
                $center->copy()->subDays(7)->toDateString(),
                $center->copy()->addDays(7)->toDateString(),
            ])
            ->with(['mirrorRepaymentLink'])
            ->get();

        if ($mirrorExpenses->isEmpty()) {
            return null;
        }

        $grouped = $mirrorExpenses->groupBy(fn (Transaction $tx) => $tx->mirrorRepaymentLink?->repayment_transaction_id);

        foreach ($grouped as $repaymentTxId => $groupMirrors) {
            if (! $repaymentTxId) {
                continue;
            }

            $groupTotal = round($groupMirrors->sum(fn (Transaction $tx) => (float) $tx->amount), 2);
            if (abs($groupTotal - $plaidAmount) <= 0.01) {
                $links = TransactionRepaymentLink::query()
                    ->where('repayment_transaction_id', (int) $repaymentTxId)
                    ->get();

                return [
                    'repayment_transaction_id' => (int) $repaymentTxId,
                    'mirror_transactions' => $groupMirrors,
                    'mirror_transaction_ids' => $groupMirrors->pluck('id')->values()->all(),
                    'repaid_transaction_ids' => $links->pluck('repaid_transaction_id')->values()->all(),
                    'repaid_user_id' => $links->first()?->repaid_user_id,
                    'total' => $groupTotal,
                ];
            }
        }

        return null;
    }

    public function normalizeMerchantKey(string $name): string
    {
        return PlaidMerchantRule::normalizeKey($name);
    }

    /**
     * @param  array<string, mixed>  $plaidRow
     * @return array{
     *     category_id: int|null,
     *     type: string,
     *     fund_id: int|null,
     *     advance_fund_id: int|null,
     *     is_non_necessity: bool,
     *     confidence_score: float,
     *     is_auto_eligible: bool,
     *     description: string|null,
     *     is_debt_payment: bool,
     *     debt_id: int|null,
     *     split_data: list<array{user_id: int, share_percentage: float}>|null
     * }
     */
    public function getSuggestion(array $plaidRow, int $userId): array
    {
        $merchantRaw = (string) (data_get($plaidRow, 'merchant_name') ?? data_get($plaidRow, 'name') ?? '');
        $key = $this->normalizeMerchantKey($merchantRaw);

        $plaidAmount = (float) data_get($plaidRow, 'amount', 0);
        $typeFromPlaid = $plaidAmount >= 0 ? 'expense' : 'income';

        $rule = PlaidMerchantRule::query()
            ->where('user_id', $userId)
            ->where('merchant_key', $key)
            ->first();

        if ($rule === null) {
            return [
                'category_id' => null,
                'type' => $typeFromPlaid,
                'fund_id' => null,
                'advance_fund_id' => null,
                'is_non_necessity' => false,
                'confidence_score' => 0.0,
                'is_auto_eligible' => false,
                'description' => null,
                'is_debt_payment' => false,
                'debt_id' => null,
                'split_data' => null,
            ];
        }

        return [
            'category_id' => $rule->category_id,
            'type' => (string) $rule->type,
            'fund_id' => $rule->fund_id,
            'advance_fund_id' => $rule->advance_fund_id,
            'is_non_necessity' => (bool) $rule->is_non_necessity,
            'confidence_score' => $rule->confidenceScore(),
            'is_auto_eligible' => $rule->action === 'dismiss' ? false : $rule->isAutoCreateEligible(),
            'description' => $rule->description,
            'is_debt_payment' => (bool) $rule->is_debt_payment,
            'debt_id' => $rule->debt_id,
            'split_data' => $rule->split_data,
        ];
    }

    public function recordConfirmation(PlaidMerchantRule $rule): void
    {
        $rule->increment('confirmation_count');
        $rule->increment('total_seen_count');
    }

    public function recordSeen(PlaidMerchantRule $rule): void
    {
        $rule->increment('total_seen_count');
    }

    /**
     * @param  array<string, mixed>  $confirmedSettings
     */
    public function learnFromConfirmation(int $userId, string $merchantName, array $confirmedSettings): PlaidMerchantRule
    {
        $key = $this->normalizeMerchantKey($merchantName);

        $rule = PlaidMerchantRule::query()->firstOrNew([
            'user_id' => $userId,
            'merchant_key' => $key,
        ]);

        $allowed = ['category_id', 'type', 'fund_id', 'advance_fund_id', 'is_non_necessity', 'is_split', 'action', 'description', 'is_debt_payment', 'debt_id', 'split_data'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $confirmedSettings)) {
                $rule->{$field} = $confirmedSettings[$field];
            }
        }

        if (! array_key_exists('action', $confirmedSettings)) {
            $rule->action = 'categorize';
        }

        $rule->confirmation_count = (int) $rule->confirmation_count + 1;
        $rule->total_seen_count = (int) $rule->total_seen_count + 1;
        $rule->save();
        $rule->refresh();

        return $rule;
    }

    /**
     * Teach the matcher to auto-dismiss future Plaid rows for this merchant key (sync path uses `action=dismiss`).
     */
    public function learnDismissRule(int $userId, string $merchantName): PlaidMerchantRule
    {
        $key = $this->normalizeMerchantKey($merchantName);

        $rule = PlaidMerchantRule::query()->firstOrNew([
            'user_id' => $userId,
            'merchant_key' => $key,
        ]);

        $rule->action = 'dismiss';
        $rule->total_seen_count = (int) $rule->total_seen_count + 1;
        $rule->save();
        $rule->refresh();

        return $rule;
    }

    /**
     * Ledger rows that plausibly match a pending import (wider date window than auto-calibration matching).
     *
     * Only transactions **recorded by the pending import's user** (`transactions.user_id` = `PlaidPendingImport.user_id`)
     * are considered: the bank feed is per linked account owner, so suggestions must not list other family members' ledger rows.
     *
     * @return list<array{transaction: Transaction, score: float}>
     */
    public function findLedgerLinkCandidatesForPendingImport(
        PlaidPendingImport $import,
        int $familyId,
        int $dayRadius = 45,
        int $limit = 25,
    ): array {
        $expectedType = $import->suggested_type === 'income' ? 'income' : 'expense';
        $ledgerAmount = (float) $import->amount;

        $center = $import->date instanceof CarbonInterface
            ? $import->date->copy()->startOfDay()
            : Carbon::parse((string) $import->date)->startOfDay();

        $merchantRaw = (string) ($import->merchant_name ?? $import->raw_name ?? '');
        $merchantLower = mb_strtolower(trim($merchantRaw));

        $candidates = Transaction::query()
            ->with('category')
            ->where('family_id', $familyId)
            ->where('user_id', $import->user_id)
            ->whereNull('plaid_transaction_id')
            ->where('type', $expectedType)
            ->whereBetween('transaction_date', [
                $center->copy()->subDays($dayRadius)->toDateString(),
                $center->copy()->addDays($dayRadius)->toDateString(),
            ])
            ->whereBetween('amount', [
                $ledgerAmount - 0.01,
                $ledgerAmount + 0.01,
            ])
            ->orderByDesc('transaction_date')
            ->limit(200)
            ->get();

        $scored = [];
        foreach ($candidates as $transaction) {
            $scored[] = [
                'transaction' => $transaction,
                'score' => $this->merchantSimilarityScore($merchantLower, (string) ($transaction->description ?? '')),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Whether a ledger row is safe to link to this pending import (type, amount, date drift, unlinked, same recorder).
     */
    public function canLinkPendingImportToLedger(
        PlaidPendingImport $import,
        Transaction $ledger,
        int $maxDateDriftDays = 60,
    ): bool {
        if (filled($ledger->plaid_transaction_id)) {
            return false;
        }

        if ((int) $ledger->user_id !== (int) $import->user_id) {
            return false;
        }

        $expectedType = $import->suggested_type === 'income' ? 'income' : 'expense';
        if ($ledger->type !== $expectedType) {
            return false;
        }

        if (abs((float) $ledger->amount - (float) $import->amount) > 0.01) {
            return false;
        }

        $pendingDay = $import->date instanceof CarbonInterface
            ? $import->date->copy()->startOfDay()
            : Carbon::parse((string) $import->date)->startOfDay();

        $ledgerDay = $ledger->transaction_date instanceof CarbonInterface
            ? $ledger->transaction_date->copy()->startOfDay()
            : Carbon::parse((string) $ledger->transaction_date)->startOfDay();

        if ($pendingDay->diffInDays($ledgerDay) > $maxDateDriftDays) {
            return false;
        }

        return true;
    }

    private function merchantSimilarityScore(string $merchantLower, string $description): float
    {
        $descriptionLower = mb_strtolower(trim($description));

        if ($merchantLower !== '' && $descriptionLower !== '') {
            if (str_contains($descriptionLower, $merchantLower) || str_contains($merchantLower, $descriptionLower)) {
                return 1.0;
            }
        }

        $percent = 0.0;
        similar_text($merchantLower, $descriptionLower, $percent);

        return min(1.0, $percent / 100.0);
    }
}
