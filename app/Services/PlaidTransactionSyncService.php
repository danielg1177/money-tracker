<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryUserDefault;
use App\Models\Debt;
use App\Models\FundRule;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use Throwable;

class PlaidTransactionSyncService
{
    public function __construct(
        private PlaidClient $plaidClient,
        private PlaidMatchingService $matchingService,
        private TransactionService $transactionService,
    ) {}

    /**
     * Pull transactions via /transactions/sync, advance the stored cursor, and return raw Plaid payloads.
     * Persists `plaid_pending_imports` (and may auto-create ledger rows when merchant rules qualify).
     *
     * @return array{
     *     counts: array{added: int, modified: int, removed: int},
     *     added: list<array<string, mixed>>,
     *     modified: list<array<string, mixed>>,
     *     removed: list<array<string, mixed>>,
     *     accounts: list<array<string, mixed>>
     * }
     */
    public function syncItem(PlaidItem $item): array
    {
        $added = [];
        $modified = [];
        $removed = [];
        /** @var array<string, array<string, mixed>> $accountsById */
        $accountsById = [];

        $cursor = $item->transactions_cursor;
        $hasMore = true;

        while ($hasMore) {
            $body = [
                'access_token' => $item->access_token,
                'options' => [
                    'include_personal_finance_category' => false,
                ],
            ];

            if (filled($cursor)) {
                $body['cursor'] = $cursor;
            }

            /** @var array<string, mixed> $json */
            $json = $this->plaidClient->post('/transactions/sync', $body);

            $hasMore = (bool) ($json['has_more'] ?? false);
            $cursor = $json['next_cursor'] ?? $cursor;

            foreach ($json['added'] ?? [] as $row) {
                if (is_array($row)) {
                    $added[] = $row;
                }
            }
            foreach ($json['modified'] ?? [] as $row) {
                if (is_array($row)) {
                    $modified[] = $row;
                }
            }
            foreach ($json['removed'] ?? [] as $row) {
                if (is_array($row)) {
                    $removed[] = $row;
                }
            }
            foreach ($json['accounts'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $aid = $row['account_id'] ?? null;
                if (is_string($aid) && $aid !== '') {
                    $accountsById[$aid] = $row;
                }
            }
        }

        $item->forceFill(['transactions_cursor' => $cursor])->save();

        $this->processSyncedTransactions($item, $added, $modified, $removed);

        return [
            'counts' => [
                'added' => count($added),
                'modified' => count($modified),
                'removed' => count($removed),
            ],
            'added' => $added,
            'modified' => $modified,
            'removed' => $removed,
            'accounts' => array_values($accountsById),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $added
     * @param  list<array<string, mixed>>  $modified
     * @param  list<array<string, mixed>>  $removed
     */
    public function processSyncedTransactions(PlaidItem $item, array $added, array $modified, array $removed): void
    {
        $user = User::query()->find($item->user_id);
        if ($user === null) {
            return;
        }

        $familyId = $user->family_id;

        foreach ($added as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->processAddedRow($item, $user, $familyId, $row);
        }

        foreach ($modified as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->processModifiedRow($user, $familyId, $row);
        }

        foreach ($removed as $row) {
            $this->processRemovedRow($row);
        }
    }

    /**
     * Paginated /transactions/get for calibration flows.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchByDateRange(PlaidItem $item, string $startDate, string $endDate): array
    {
        $all = [];
        $offset = 0;
        $count = 500;

        while (true) {
            /** @var array<string, mixed> $json */
            $json = $this->plaidClient->post('/transactions/get', [
                'access_token' => $item->access_token,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'options' => [
                    'count' => $count,
                    'offset' => $offset,
                ],
            ]);

            $batch = $json['transactions'] ?? [];
            if (! is_array($batch)) {
                break;
            }

            foreach ($batch as $txn) {
                if (is_array($txn)) {
                    $all[] = $txn;
                }
            }

            $got = count($batch);
            if ($got === 0) {
                break;
            }

            $total = (int) ($json['total_transactions'] ?? 0);
            $offset += $got;

            if ($total > 0 && $offset >= $total) {
                break;
            }

            if ($total === 0) {
                break;
            }
        }

        return $all;
    }

    /**
     * Refresh institution metadata (best-effort).
     */
    public function hydrateInstitution(PlaidItem $item): void
    {
        $json = $this->plaidClient->post('/item/get', [
            'access_token' => $item->access_token,
        ]);

        $institutionId = data_get($json, 'item.institution_id');
        $institutionName = null;

        if (filled($institutionId)) {
            try {
                $inst = $this->plaidClient->post('/institutions/get_by_id', [
                    'institution_id' => $institutionId,
                    'country_codes' => ['US'],
                    'options' => ['include_optional_metadata' => true],
                ]);
                $institutionName = data_get($inst, 'institution.name');
            } catch (Throwable) {
                $institutionName = null;
            }
        }

        $item->forceFill([
            'institution_id' => $institutionId ?: $item->institution_id,
            'institution_name' => $institutionName ?: $item->institution_name,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processAddedRow(PlaidItem $item, User $user, ?int $familyId, array $row): void
    {
        $plaidTransactionId = $this->extractPlaidTransactionId($row);
        if ($plaidTransactionId === null) {
            return;
        }

        if (PlaidPendingImport::query()->where('plaid_transaction_id', $plaidTransactionId)->exists()) {
            return;
        }

        if ($familyId !== null && Transaction::query()
            ->where('family_id', $familyId)
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
                'suggested_description' => $suggestion['description'] ?? null,
                'suggested_is_debt_payment' => (bool) ($suggestion['is_debt_payment'] ?? false),
                'suggested_split_data' => $suggestion['split_data'] ?? null,
                'confidence_score' => $suggestion['confidence_score'],
                'status' => 'dismissed',
                'dismiss_source' => 'auto',
                'transaction_id' => null,
                'raw_payload' => $row,
                'is_transfer' => false,
                'plaid_category_primary' => $this->extractPlaidCategoryPrimary($row),
                'plaid_category_detailed' => $this->extractPlaidCategoryDetailed($row),
            ]);
            $this->matchingService->recordSeen($rule);

            return;
        }

        $pending = PlaidPendingImport::query()->create([
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
            'suggested_description' => $suggestion['description'] ?? null,
            'suggested_is_debt_payment' => (bool) ($suggestion['is_debt_payment'] ?? false),
            'suggested_split_data' => $suggestion['split_data'] ?? null,
            'confidence_score' => $suggestion['confidence_score'],
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => $row,
            'is_transfer' => false,
            'plaid_category_primary' => $this->extractPlaidCategoryPrimary($row),
            'plaid_category_detailed' => $this->extractPlaidCategoryDetailed($row),
        ]);

        $canAutoCreate = $suggestion['is_auto_eligible'] && $user->family_id !== null;

        if ($canAutoCreate && ($suggestion['is_debt_payment'] ?? false)) {
            $learnedDebtId = $suggestion['debt_id'] ?? null;
            if ($learnedDebtId === null) {
                $canAutoCreate = false;
            } else {
                $debt = Debt::query()
                    ->where('id', $learnedDebtId)
                    ->where('debtor_id', $user->id)
                    ->where('is_pending_closeout', false)
                    ->where('balance', '>', 0)
                    ->first();
                $canAutoCreate = $debt !== null;
            }
        }

        if ($canAutoCreate) {
            try {
                $payload = $this->buildAutoCreateTransactionPayload(
                    $user,
                    $suggestion,
                    $rule,
                    $merchantRaw,
                    abs($plaidAmount),
                    $dateStr,
                );

                $transaction = $this->transactionService->createTransaction($payload, $user);

                $advanceId = $payload['type'] === 'expense' ? ($payload['advance_fund_id'] ?? null) : null;
                $tagFundId = $suggestion['fund_id'] ?? $advanceId;
                if ($tagFundId !== null) {
                    $transaction->forceFill(['fund_id' => $tagFundId])->save();
                }

                $transaction->forceFill([
                    'plaid_transaction_id' => $plaidTransactionId,
                    'import_source' => 'plaid',
                ])->save();

                $pending->forceFill([
                    'status' => 'auto_created',
                    'transaction_id' => $transaction->id,
                ])->save();
            } catch (Throwable) {
                // Leave pending row for manual resolution.
            }
        }

        if ($rule !== null) {
            $this->matchingService->recordSeen($rule);
        }
    }

    /**
     * @param  array{
     *     category_id: int|null,
     *     type: string,
     *     fund_id: int|null,
     *     advance_fund_id: int|null,
     *     is_non_necessity: bool
     * }  $suggestion
     * @return array<string, mixed>
     */
    private function buildAutoCreateTransactionPayload(
        User $user,
        array $suggestion,
        ?PlaidMerchantRule $rule,
        string $merchantRaw,
        float $amount,
        string $dateStr,
    ): array {
        $type = (string) $suggestion['type'];
        $categoryId = $suggestion['category_id'];
        $ruleDescription = $rule !== null ? trim((string) ($rule->description ?? '')) : '';
        $description = $ruleDescription !== '' ? $ruleDescription : (trim($merchantRaw) !== '' ? trim($merchantRaw) : 'Plaid import');

        if ($type === 'income') {
            return [
                'type' => 'income',
                'amount' => $amount,
                'transaction_date' => $dateStr,
                'description' => $description,
                'category_id' => $categoryId,
                'is_split' => false,
                'split_data' => null,
                'advance_fund_id' => null,
                'is_non_necessity' => false,
            ];
        }

        $advanceFundId = $suggestion['advance_fund_id'];
        $ruleNonNecessity = (bool) ($suggestion['is_non_necessity'] ?? false);
        $isSplit = false;
        $splitData = null;

        $category = null;
        $userDefaults = null;
        if ($categoryId !== null && $user->family_id !== null) {
            $category = Category::query()
                ->where('id', $categoryId)
                ->where('family_id', $user->family_id)
                ->first();
            if ($category !== null) {
                $userDefaults = CategoryUserDefault::query()
                    ->where('category_id', $category->id)
                    ->where('user_id', $user->id)
                    ->first();
            }
        }

        if ($category !== null && $category->is_expense) {
            $splitTemplate = $category->split_default;
            $wantsCategorySplit = $category->is_split_default
                && is_array($splitTemplate)
                && count($splitTemplate) > 0;

            if ($wantsCategorySplit || ($rule !== null && $rule->is_split)) {
                $learnedSplitData = $rule !== null ? $rule->split_data : null;
                if (
                    is_array($learnedSplitData)
                    && count($learnedSplitData) > 0
                    && SplitCalculator::validate($learnedSplitData)
                ) {
                    $splitData = $learnedSplitData;
                    $isSplit = true;
                } else {
                    $splitData = $this->equalFamilySplitDataOrNull($user);
                    $isSplit = $splitData !== null;
                }
            }

            $advanceFromCategory = $userDefaults?->advance_fund_id;
            if ($advanceFromCategory !== null && (int) $advanceFromCategory !== 0) {
                $advanceFundId = (int) $advanceFromCategory;
            }

            $isNonNecessity = $this->resolveAutoCreateNonNecessityFlag(
                $user,
                $advanceFundId,
                $isSplit,
                $userDefaults,
                $ruleNonNecessity,
            );
        } else {
            if ($rule !== null && $rule->is_split) {
                $learnedSplitData = $rule->split_data;
                if (
                    is_array($learnedSplitData)
                    && count($learnedSplitData) > 0
                    && SplitCalculator::validate($learnedSplitData)
                ) {
                    $splitData = $learnedSplitData;
                    $isSplit = true;
                } else {
                    $splitData = $this->equalFamilySplitDataOrNull($user);
                    $isSplit = $splitData !== null;
                }
            }

            $isNonNecessity = $this->resolveAutoCreateNonNecessityFlag(
                $user,
                $advanceFundId,
                $isSplit,
                null,
                $ruleNonNecessity,
            );
        }

        $isDebtPayment = (bool) ($suggestion['is_debt_payment'] ?? false);
        $learnedDebtId = ($isDebtPayment && isset($suggestion['debt_id'])) ? (int) $suggestion['debt_id'] : null;

        $expensePayload = [
            'type' => 'expense',
            'amount' => $amount,
            'transaction_date' => $dateStr,
            'description' => $description,
            'category_id' => $categoryId,
            'is_split' => $isSplit,
            'split_data' => $splitData,
            'advance_fund_id' => $advanceFundId,
            'is_non_necessity' => $isNonNecessity,
        ];

        if ($isDebtPayment && $learnedDebtId !== null) {
            $expensePayload['is_debt_payment'] = true;
            $expensePayload['debt_id'] = $learnedDebtId;
        }

        return $expensePayload;
    }

    /**
     * @return list<array{user_id: int, share_percentage: float}>|null
     */
    private function equalFamilySplitDataOrNull(User $user): ?array
    {
        if ($user->family_id === null) {
            return null;
        }

        $familyUserIds = User::query()
            ->where('family_id', $user->family_id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $splitData = SplitCalculator::equalShareSplitData($familyUserIds);
        if ($splitData === [] || ! SplitCalculator::validate($splitData)) {
            return null;
        }

        return $splitData;
    }

    private function resolveAutoCreateNonNecessityFlag(
        User $user,
        ?int $advanceFundId,
        bool $isSplit,
        ?CategoryUserDefault $userDefaults,
        bool $ruleNonNecessity,
    ): bool {
        if ($isSplit || $advanceFundId === null || $advanceFundId === 0) {
            return false;
        }

        if (! $this->userHasNonNecessityEligibleFundRule($user->id, $advanceFundId)) {
            return false;
        }

        if ($userDefaults !== null && $userDefaults->is_non_necessity_default) {
            return true;
        }

        return $ruleNonNecessity;
    }

    private function userHasNonNecessityEligibleFundRule(int $userId, int $advanceFundId): bool
    {
        return FundRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('destination_type', 'fund')
            ->where('destination_id', $advanceFundId)
            ->where('allocation_type', 'percentage')
            ->where('allocation_base', 'remaining')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function processModifiedRow(User $user, ?int $familyId, array $row): void
    {
        $plaidTransactionId = $this->extractPlaidTransactionId($row);
        if ($plaidTransactionId === null) {
            return;
        }

        $plaidAmount = (float) data_get($row, 'amount', 0);
        $dateStr = data_get($row, 'date') ?? data_get($row, 'authorized_date');
        if (! is_string($dateStr) || $dateStr === '') {
            return;
        }

        $pending = PlaidPendingImport::query()
            ->where('plaid_transaction_id', $plaidTransactionId)
            ->first();

        if ($pending !== null && $pending->status === 'pending') {
            $pending->forceFill([
                'amount' => abs($plaidAmount),
                'date' => $dateStr,
                'raw_payload' => $row,
            ])->save();

            if ($pending->transaction_id !== null) {
                $linked = Transaction::query()->find($pending->transaction_id);
                if ($linked !== null) {
                    $linked->forceFill([
                        'amount' => abs($plaidAmount),
                        'transaction_date' => $dateStr,
                    ])->save();
                }
            }
        }

        if ($familyId !== null) {
            $transaction = Transaction::query()
                ->where('family_id', $familyId)
                ->where('plaid_transaction_id', $plaidTransactionId)
                ->first();

            if ($transaction !== null) {
                $transaction->forceFill([
                    'amount' => abs($plaidAmount),
                    'transaction_date' => $dateStr,
                ])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>|string  $row
     */
    private function processRemovedRow(array|string $row): void
    {
        $plaidTransactionId = is_string($row)
            ? $row
            : $this->extractPlaidTransactionId(is_array($row) ? $row : []);

        if ($plaidTransactionId === null) {
            return;
        }

        PlaidPendingImport::query()
            ->where('plaid_transaction_id', $plaidTransactionId)
            ->where('status', 'pending')
            ->delete();
    }

    /**
     * Run the same pending-import / auto-create path as sync `added` rows, for an arbitrary list of Plaid transaction payloads.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{pending_created: int, auto_created: int}
     */
    public function ingestPlaidRowsAsPending(PlaidItem $item, array $rows): array
    {
        $user = User::query()->find($item->user_id);
        if ($user === null) {
            return ['pending_created' => 0, 'auto_created' => 0];
        }

        $familyId = $user->family_id;
        $pendingCreated = 0;
        $autoCreated = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tid = $this->extractPlaidTransactionId($row);
            if ($tid === null) {
                continue;
            }

            $already = PlaidPendingImport::query()->where('plaid_transaction_id', $tid)->exists()
                || ($familyId !== null && Transaction::query()
                    ->where('family_id', $familyId)
                    ->where('plaid_transaction_id', $tid)
                    ->exists());

            if ($already) {
                continue;
            }

            $this->processAddedRow($item, $user, $familyId, $row);

            $pending = PlaidPendingImport::query()->where('plaid_transaction_id', $tid)->first();
            if ($pending === null) {
                continue;
            }

            if ($pending->status === 'auto_created') {
                $autoCreated++;
            } elseif ($pending->status === 'pending') {
                $pendingCreated++;
            }
        }

        return [
            'pending_created' => $pendingCreated,
            'auto_created' => $autoCreated,
        ];
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
