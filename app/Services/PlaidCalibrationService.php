<?php

namespace App\Services;

use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PlaidCalibrationService
{
    public function __construct(
        private PlaidTransactionSyncService $syncService,
        private PlaidMatchingService $matchingService,
        private TransactionService $transactionService,
    ) {}

    /**
     * Pull the last two full calendar months (ending on the last day of the previous month),
     * match Plaid rows to existing ledger transactions, and partition into matched / unmatched sets.
     *
     * @return array{
     *     matched: list<array{plaid: array<string, mixed>, ledger: Transaction, score: float}>,
     *     unmatched_plaid: list<array{plaid: array<string, mixed>, suggestion: array<string, mixed>}>,
     *     unmatched_ledger: list<Transaction>
     * }
     */
    public function buildCalibrationMatches(PlaidItem $item): array
    {
        $item->loadMissing('user');
        $user = $item->user;
        if ($user === null) {
            return [
                'matched' => [],
                'unmatched_plaid' => [],
                'unmatched_ledger' => [],
            ];
        }

        $bounds = $this->calibrationWindowBoundaries();
        $startDate = $bounds['start']->toDateString();
        $endDate = $bounds['end']->toDateString();

        /** @var list<array<string, mixed>> $plaidRows */
        $plaidRows = $this->syncService->fetchByDateRange($item, $startDate, $endDate);

        $familyId = $user->family_id;
        if ($familyId === null) {
            $unmatchedPlaid = [];
            foreach ($plaidRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $unmatchedPlaid[] = [
                    'plaid' => $row,
                    'suggestion' => $this->matchingService->getSuggestion($row, $item->user_id),
                ];
            }

            return [
                'matched' => [],
                'unmatched_plaid' => $unmatchedPlaid,
                'unmatched_ledger' => [],
            ];
        }

        /** @var Collection<int, Transaction> $ledgerInRange */
        $ledgerInRange = Transaction::query()
            ->with('category')
            ->where('family_id', $familyId)
            ->whereNull('plaid_transaction_id')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->orderBy('transaction_date')
            ->get();

        $matched = [];
        $unmatchedPlaid = [];
        $matchedLedgerIds = [];

        foreach ($plaidRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hit = $this->matchingService->findLedgerMatchWithScore($row, $familyId);
            if ($hit !== null) {
                $matched[] = [
                    'plaid' => $row,
                    'ledger' => $hit['transaction'],
                    'score' => $hit['score'],
                ];
                $matchedLedgerIds[$hit['transaction']->id] = true;

                continue;
            }

            $unmatchedPlaid[] = [
                'plaid' => $row,
                'suggestion' => $this->matchingService->getSuggestion($row, $item->user_id),
            ];
        }

        $unmatchedLedger = $ledgerInRange
            ->reject(fn (Transaction $t): bool => isset($matchedLedgerIds[$t->id]))
            ->values()
            ->all();

        return [
            'matched' => $matched,
            'unmatched_plaid' => $unmatchedPlaid,
            'unmatched_ledger' => $unmatchedLedger,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    private function calibrationWindowBoundaries(): array
    {
        $now = Carbon::now();
        $start = $now->copy()->startOfMonth()->subMonths(2);
        $end = $now->copy()->startOfMonth()->subMonth()->endOfMonth();

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Plaid `/transactions/get` rows keyed by `transaction_id` for the calibration date window.
     *
     * @return array<string, array<string, mixed>>
     */
    private function plaidRowsKeyedByTransactionId(PlaidItem $item): array
    {
        $item->loadMissing('user');
        $user = $item->user;
        if ($user === null) {
            return [];
        }

        $bounds = $this->calibrationWindowBoundaries();
        $rows = $this->syncService->fetchByDateRange($item, $bounds['start']->toDateString(), $bounds['end']->toDateString());

        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $tid = $this->extractPlaidTransactionId($row);
            if ($tid !== null) {
                $map[$tid] = $row;
            }
        }

        return $map;
    }

    /**
     * Persist calibration choices: link confirmed ledger rows to Plaid ids (and merchant rules),
     * or stage unmatched Plaid rows as pending imports.
     *
     * Each element of `$confirmedPairs` may be either:
     * - `['plaid' => array, 'ledger' => Transaction|int]` (legacy), or
     * - `['plaid_transaction_id' => string, 'ledger_transaction_id' => int, 'category_id' => int, 'type' => string, ...]`.
     * Each element of `$importAsNew` may be a full Plaid row `array` or a `transaction_id` string resolvable from the calibration window fetch.
     *
     * @return array{confirmed_linked: int, imported_pending: int}
     */
    public function applyCalibrationResults(PlaidItem $item, array $confirmedPairs, array $importAsNew): array
    {
        return DB::transaction(function () use ($item, $confirmedPairs, $importAsNew): array {
            $item->loadMissing('user');
            $user = $item->user;
            if ($user === null) {
                return ['confirmed_linked' => 0, 'imported_pending' => 0];
            }

            $plaidMap = $this->plaidRowsKeyedByTransactionId($item);

            $linked = 0;
            foreach ($confirmedPairs as $pair) {
                if (! is_array($pair)) {
                    continue;
                }

                if (isset($pair['plaid_transaction_id'], $pair['ledger_transaction_id'])) {
                    if ($this->applyStructuredCalibrationPair($item, $user, $pair, $plaidMap)) {
                        $linked++;
                    }

                    continue;
                }

                $plaid = $pair['plaid'] ?? null;
                $ledgerRef = $pair['ledger'] ?? null;
                if (! is_array($plaid) || $ledgerRef === null) {
                    continue;
                }

                $ledger = $ledgerRef instanceof Transaction
                    ? $ledgerRef
                    : Transaction::query()->find($ledgerRef);

                if ($ledger === null) {
                    continue;
                }

                if ($user->family_id === null || $ledger->family_id !== $user->family_id) {
                    continue;
                }

                $plaidTransactionId = $this->extractPlaidTransactionId($plaid);
                if ($plaidTransactionId === null) {
                    continue;
                }

                $merchantName = (string) (data_get($plaid, 'merchant_name') ?? data_get($plaid, 'name') ?? '');

                $this->matchingService->learnFromConfirmation($item->user_id, $merchantName, [
                    'category_id' => $ledger->category_id,
                    'type' => $ledger->type,
                    'fund_id' => $ledger->fund_id,
                    'advance_fund_id' => $ledger->advance_fund_id,
                    'is_non_necessity' => $ledger->is_non_necessity,
                    'is_split' => $ledger->is_split,
                    'action' => 'categorize',
                ]);

                $ledger->forceFill([
                    'plaid_transaction_id' => $plaidTransactionId,
                    'import_source' => 'plaid',
                ])->save();

                $linked++;
            }

            $imported = 0;
            foreach ($importAsNew as $entry) {
                $row = null;
                if (is_string($entry)) {
                    $row = $plaidMap[$entry] ?? null;
                } elseif (is_array($entry)) {
                    $row = $entry;
                }
                if (! is_array($row)) {
                    continue;
                }

                $tid = $this->extractPlaidTransactionId($row);
                if ($tid === null) {
                    continue;
                }

                $before = PlaidPendingImport::query()->where('plaid_transaction_id', $tid)->exists()
                    || ($user->family_id !== null && Transaction::query()
                        ->where('family_id', $user->family_id)
                        ->where('plaid_transaction_id', $tid)
                        ->exists());

                $this->createPendingImportFromPlaidRow($item, $user, $row);

                if (! $before && PlaidPendingImport::query()->where('plaid_transaction_id', $tid)->exists()) {
                    $imported++;
                }
            }

            return [
                'confirmed_linked' => $linked,
                'imported_pending' => $imported,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $pair
     * @param  array<string, array<string, mixed>>  $plaidMap
     */
    private function applyStructuredCalibrationPair(PlaidItem $item, User $user, array $pair, array $plaidMap): bool
    {
        $plaidTransactionId = $pair['plaid_transaction_id'];
        if (! is_string($plaidTransactionId) || $plaidTransactionId === '') {
            return false;
        }

        $plaidRow = $plaidMap[$plaidTransactionId] ?? null;
        if (! is_array($plaidRow)) {
            return false;
        }

        $ledgerId = $pair['ledger_transaction_id'];
        $ledger = Transaction::query()->find($ledgerId);
        if ($ledger === null || $user->family_id === null || $ledger->family_id !== $user->family_id) {
            return false;
        }

        $categoryId = $pair['category_id'] ?? null;
        $type = $pair['type'] ?? null;
        if ($categoryId === null || ! is_string($type) || $type === '') {
            return false;
        }

        $advanceFundId = $pair['advance_fund_id'] ?? null;
        $isNonNecessity = (bool) ($pair['is_non_necessity'] ?? false);

        $updatePayload = [
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => (float) $ledger->amount,
            'transaction_date' => $ledger->transaction_date->format('Y-m-d'),
            'description' => $ledger->description,
            'is_split' => (bool) $ledger->is_split,
            'split_data' => $ledger->split_data,
            'advance_fund_id' => $advanceFundId,
            'is_non_necessity' => $isNonNecessity,
        ];

        $this->transactionService->updateTransaction($ledger, $updatePayload);

        $ledger->refresh();

        if (array_key_exists('fund_id', $pair)) {
            $ledger->forceFill(['fund_id' => $pair['fund_id']])->save();
        }

        $merchantName = (string) (data_get($plaidRow, 'merchant_name') ?? data_get($plaidRow, 'name') ?? '');

        $this->matchingService->learnFromConfirmation($item->user_id, $merchantName, [
            'category_id' => $categoryId,
            'type' => $type,
            'fund_id' => $pair['fund_id'] ?? null,
            'advance_fund_id' => $advanceFundId,
            'is_non_necessity' => $isNonNecessity,
            'is_split' => (bool) $ledger->is_split,
            'action' => 'categorize',
        ]);

        $ledger->forceFill([
            'plaid_transaction_id' => $plaidTransactionId,
            'import_source' => 'plaid',
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createPendingImportFromPlaidRow(PlaidItem $item, User $user, array $row): void
    {
        $plaidTransactionId = $this->extractPlaidTransactionId($row);
        if ($plaidTransactionId === null) {
            return;
        }

        if (PlaidPendingImport::query()->where('plaid_transaction_id', $plaidTransactionId)->exists()) {
            return;
        }

        if ($user->family_id !== null && Transaction::query()
            ->where('family_id', $user->family_id)
            ->where('plaid_transaction_id', $plaidTransactionId)
            ->exists()) {
            return;
        }

        $suggestion = $this->matchingService->getSuggestion($row, $item->user_id);

        $merchantRaw = (string) (data_get($row, 'merchant_name') ?? data_get($row, 'name') ?? '');
        $key = $this->matchingService->normalizeMerchantKey($merchantRaw);
        $rule = PlaidMerchantRule::query()
            ->where('user_id', $item->user_id)
            ->where('merchant_key', $key)
            ->first();

        $plaidAmount = (float) data_get($row, 'amount', 0);
        $dateStr = data_get($row, 'date') ?? data_get($row, 'authorized_date');
        if (! is_string($dateStr) || $dateStr === '') {
            return;
        }

        if ($rule !== null && $rule->action === 'dismiss') {
            PlaidPendingImport::query()->create([
                'user_id' => $item->user_id,
                'plaid_item_id' => $item->id,
                'plaid_transaction_id' => $plaidTransactionId,
                'plaid_account_id' => is_string(data_get($row, 'account_id')) ? data_get($row, 'account_id') : null,
                'amount' => abs($plaidAmount),
                'date' => $dateStr,
                'merchant_name' => is_string(data_get($row, 'merchant_name')) ? data_get($row, 'merchant_name') : null,
                'raw_name' => (string) (data_get($row, 'name') ?? ''),
                'suggested_category_id' => $suggestion['category_id'],
                'suggested_type' => $suggestion['type'],
                'suggested_fund_id' => $suggestion['fund_id'],
                'suggested_advance_fund_id' => $suggestion['advance_fund_id'],
                'suggested_is_non_necessity' => $suggestion['is_non_necessity'],
                'confidence_score' => $suggestion['confidence_score'],
                'status' => 'dismissed',
                'transaction_id' => null,
                'raw_payload' => $row,
                'is_transfer' => false,
                'plaid_category_primary' => $this->extractPlaidCategoryPrimary($row),
                'plaid_category_detailed' => $this->extractPlaidCategoryDetailed($row),
            ]);
            $this->matchingService->recordSeen($rule);

            return;
        }

        PlaidPendingImport::query()->create([
            'user_id' => $item->user_id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => $plaidTransactionId,
            'plaid_account_id' => is_string(data_get($row, 'account_id')) ? data_get($row, 'account_id') : null,
            'amount' => abs($plaidAmount),
            'date' => $dateStr,
            'merchant_name' => is_string(data_get($row, 'merchant_name')) ? data_get($row, 'merchant_name') : null,
            'raw_name' => (string) (data_get($row, 'name') ?? ''),
            'suggested_category_id' => $suggestion['category_id'],
            'suggested_type' => $suggestion['type'],
            'suggested_fund_id' => $suggestion['fund_id'],
            'suggested_advance_fund_id' => $suggestion['advance_fund_id'],
            'suggested_is_non_necessity' => $suggestion['is_non_necessity'],
            'confidence_score' => $suggestion['confidence_score'],
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => $row,
            'is_transfer' => false,
            'plaid_category_primary' => $this->extractPlaidCategoryPrimary($row),
            'plaid_category_detailed' => $this->extractPlaidCategoryDetailed($row),
        ]);

        if ($rule !== null) {
            $this->matchingService->recordSeen($rule);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractPlaidCategoryPrimary(array $row): ?string
    {
        $v = data_get($row, 'personal_finance_category.primary');

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractPlaidCategoryDetailed(array $row): ?string
    {
        $v = data_get($row, 'personal_finance_category.detailed');

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractPlaidTransactionId(array $row): ?string
    {
        $id = data_get($row, 'transaction_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
