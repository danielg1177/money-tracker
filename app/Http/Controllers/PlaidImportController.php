<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyPlaidCalibrationRequest;
use App\Http\Requests\ConfirmSplitImportRequest;
use App\Http\Requests\LinkPlaidPendingImportRequest;
use App\Http\Requests\StoreImportConfirmRequest;
use App\Models\Category;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Services\ClosedMonthGuard;
use App\Services\PlaidCalibrationService;
use App\Services\PlaidMatchingService;
use App\Services\PlaidTransactionSyncService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class PlaidImportController extends Controller
{
    public function __construct(
        private PlaidCalibrationService $calibrationService,
        private PlaidMatchingService $matchingService,
        private PlaidTransactionSyncService $syncService,
        private TransactionService $transactionService,
        private ClosedMonthGuard $closedMonthGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->boolean('count_only')) {
            $count = PlaidPendingImport::query()
                ->where('user_id', $user->id)
                ->pending()
                ->count();

            $autoCreatedCount = PlaidPendingImport::query()
                ->where('user_id', $user->id)
                ->where('status', 'auto_created')
                ->whereNull('reviewed_at')
                ->count();

            $dismissedCount = PlaidPendingImport::query()
                ->where('user_id', $user->id)
                ->where('status', 'dismissed')
                ->where('dismiss_source', 'auto')
                ->whereNull('reviewed_at')
                ->count();

            return response()->json([
                'count' => $count,
                'auto_created_count' => $autoCreatedCount,
                'dismissed_count' => $dismissedCount,
            ]);
        }

        $pending = PlaidPendingImport::query()
            ->where('user_id', $user->id)
            ->pending()
            ->where('is_transfer', false)
            ->with(['suggestedCategory', 'plaidItem'])
            ->orderByDesc('date')
            ->get();

        $transfers = PlaidPendingImport::query()
            ->where('user_id', $user->id)
            ->pending()
            ->where('is_transfer', true)
            ->with(['suggestedCategory', 'plaidItem'])
            ->orderByDesc('date')
            ->get();

        $autoCreated = PlaidPendingImport::query()
            ->where('user_id', $user->id)
            ->where('status', 'auto_created')
            ->whereNull('reviewed_at')
            ->with([
                'suggestedCategory',
                'plaidItem',
                'transaction.user',
                'transaction.category',
                'transaction.splits.user',
                'transaction.debt.creditor',
                'transaction.debt.debtor',
                'transaction.debt.fund',
                'transaction.advanceFund',
                'transaction.fund',
                'transaction.paidByUser',
            ])
            ->orderByDesc('date')
            ->get();

        $dismissed = PlaidPendingImport::query()
            ->where('user_id', $user->id)
            ->where('status', 'dismissed')
            ->where('dismiss_source', 'auto')
            ->whereNull('reviewed_at')
            ->with(['plaidItem'])
            ->orderByDesc('date')
            ->get();

        return response()->json([
            'pending' => $pending,
            'transfers' => $transfers,
            'auto_created' => $autoCreated,
            'dismissed' => $dismissed,
        ]);
    }

    public function approveAutoCreated(Request $request, PlaidPendingImport $pendingImport): Response
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'auto_created') {
            abort(422, 'This import was not auto-created.');
        }

        $transaction = $pendingImport->transaction;
        if ($transaction === null) {
            abort(422, 'No linked transaction found.');
        }

        $merchantName = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
        $this->matchingService->learnFromConfirmation($request->user()->id, $merchantName, [
            'category_id' => $transaction->category_id,
            'type' => $transaction->type,
            'fund_id' => $transaction->fund_id,
            'advance_fund_id' => $transaction->advance_fund_id,
            'is_non_necessity' => (bool) $transaction->is_non_necessity,
            'is_split' => (bool) $transaction->is_split,
            'action' => 'categorize',
            'description' => $transaction->description,
            'is_debt_payment' => (bool) $transaction->is_debt_payment,
            'debt_id' => $transaction->debt_id,
            'split_data' => $transaction->is_split ? $transaction->split_data : null,
        ]);

        $pendingImport->forceFill(['reviewed_at' => now()])->save();

        return response()->noContent();
    }

    public function correctAutoCreated(Request $request, PlaidPendingImport $pendingImport): JsonResponse
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'auto_created') {
            abort(422, 'This import was not auto-created.');
        }

        $transaction = $pendingImport->transaction;
        if ($transaction === null) {
            abort(422, 'No linked transaction found.');
        }

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', 'string', 'in:expense,income'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'advance_fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'is_non_necessity' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_debt_payment' => ['boolean'],
        ]);

        $transaction->forceFill([
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'fund_id' => $validated['fund_id'] ?? null,
            'advance_fund_id' => $validated['advance_fund_id'] ?? null,
            'is_non_necessity' => (bool) ($validated['is_non_necessity'] ?? false),
            'description' => $validated['description'] ?? $transaction->description,
            'is_debt_payment' => (bool) ($validated['is_debt_payment'] ?? $transaction->is_debt_payment),
        ])->save();

        $merchantName = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
        $this->matchingService->learnFromConfirmation($request->user()->id, $merchantName, [
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'fund_id' => $validated['fund_id'] ?? null,
            'advance_fund_id' => $validated['advance_fund_id'] ?? null,
            'is_non_necessity' => (bool) ($validated['is_non_necessity'] ?? false),
            'is_split' => (bool) $transaction->is_split,
            'action' => 'categorize',
            'description' => $validated['description'] ?? $transaction->description,
            'is_debt_payment' => (bool) ($validated['is_debt_payment'] ?? $transaction->is_debt_payment),
            'debt_id' => $validated['debt_id'] ?? $transaction->debt_id,
            'split_data' => $transaction->is_split ? $transaction->split_data : null,
        ]);

        $pendingImport->forceFill(['reviewed_at' => now()])->save();

        return response()->json(
            $transaction->fresh()->load([
                'user',
                'category',
                'splits.user',
                'debt.creditor',
                'debt.debtor',
                'debt.fund',
                'advanceFund',
                'fund',
                'paidByUser',
            ])
        );
    }

    public function acknowledgeAutoDismiss(Request $request, PlaidPendingImport $pendingImport): Response
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'dismissed' || $pendingImport->dismiss_source !== 'auto') {
            abort(422, 'This import is not an auto-dismissed entry.');
        }

        $merchantName = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
        $key = $this->matchingService->normalizeMerchantKey($merchantName);
        $rule = PlaidMerchantRule::query()
            ->where('user_id', $pendingImport->user_id)
            ->where('merchant_key', $key)
            ->first();

        if ($rule !== null) {
            $this->matchingService->recordSeen($rule);
        }

        $pendingImport->forceFill(['reviewed_at' => now()])->save();

        return response()->noContent();
    }

    public function restoreFromDismiss(Request $request, PlaidPendingImport $pendingImport): JsonResponse
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'dismissed' || $pendingImport->dismiss_source !== 'auto') {
            abort(422, 'This import is not an auto-dismissed entry.');
        }

        $user = $request->user();
        if ($user->family_id === null) {
            return response()->json(['message' => 'You must belong to a family to create transactions.'], 403);
        }

        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', 'string', 'in:expense,income'],
            'fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'advance_fund_id' => ['nullable', 'integer', 'exists:funds,id'],
            'is_non_necessity' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $description = $validated['description'] ?? null;
        if ($description === null || $description === '') {
            $description = trim((string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? ''));
        }
        if ($description === '') {
            $description = 'Plaid import';
        }

        $payload = [
            'type' => $validated['type'],
            'amount' => (float) $pendingImport->amount,
            'transaction_date' => $pendingImport->date->format('Y-m-d'),
            'description' => $description,
            'category_id' => $validated['category_id'],
            'fund_id' => $validated['fund_id'] ?? null,
            'advance_fund_id' => $validated['advance_fund_id'] ?? null,
            'is_non_necessity' => (bool) ($validated['is_non_necessity'] ?? false),
            'is_split' => false,
        ];

        try {
            $this->closedMonthGuard->assertTransactionPayloadOpen($user, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $transaction = $this->transactionService->createTransaction($payload, $user);

        if (! empty($validated['fund_id'])) {
            $transaction->forceFill(['fund_id' => $validated['fund_id']])->save();
        }

        $transaction->forceFill([
            'plaid_transaction_id' => $pendingImport->plaid_transaction_id,
            'import_source' => 'plaid',
        ])->save();

        $merchantName = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
        $this->matchingService->learnFromConfirmation($user->id, $merchantName, [
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'fund_id' => $validated['fund_id'] ?? null,
            'advance_fund_id' => $validated['advance_fund_id'] ?? null,
            'is_non_necessity' => (bool) ($validated['is_non_necessity'] ?? false),
            'is_split' => false,
            'action' => 'categorize',
            'description' => $description,
            'is_debt_payment' => false,
            'debt_id' => null,
            'split_data' => null,
        ]);

        $pendingImport->forceFill([
            'status' => 'confirmed',
            'transaction_id' => $transaction->id,
            'reviewed_at' => now(),
        ])->save();

        return response()->json(
            $transaction->fresh()->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund'])
        );
    }

    public function confirm(StoreImportConfirmRequest $request, PlaidPendingImport $pendingImport): JsonResponse
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            return response()->json(['message' => 'This import is not pending confirmation.'], 422);
        }

        $user = $request->user();
        if ($user->family_id === null) {
            return response()->json(['message' => 'You must belong to a family to confirm imports.'], 403);
        }

        $validated = $request->validated();

        $payload = $this->buildTransactionPayloadFromImportFields(
            $validated,
            (float) $pendingImport->amount,
            $pendingImport->date->format('Y-m-d'),
            $pendingImport,
        );
        $resolvedTagFundId = $this->resolvedTagFundIdFromImportFields($validated);

        try {
            $this->closedMonthGuard->assertTransactionPayloadOpen($user, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $transaction = $this->transactionService->createTransaction($payload, $user);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($resolvedTagFundId !== null) {
            $transaction->forceFill(['fund_id' => $resolvedTagFundId])->save();
        }

        $transaction->forceFill([
            'plaid_transaction_id' => $pendingImport->plaid_transaction_id,
            'import_source' => 'plaid',
        ])->save();

        $merchantName = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
        $payTowardDebt = ($validated['type'] ?? '') === 'expense' && ! empty($validated['debt_id']);
        $isSplit = (bool) ($validated['is_split'] ?? false);
        $resolvedAdvanceFundId = ($validated['type'] === 'expense' && ! $payTowardDebt) ? ($validated['advance_fund_id'] ?? null) : null;

        $this->matchingService->learnFromConfirmation($user->id, $merchantName, [
            'category_id' => $validated['category_id'],
            'type' => $validated['type'],
            'fund_id' => $resolvedTagFundId,
            'advance_fund_id' => $resolvedAdvanceFundId,
            'is_non_necessity' => (bool) ($validated['is_non_necessity'] ?? false),
            'is_split' => $isSplit,
            'action' => 'categorize',
            'description' => $payload['description'],
            'is_debt_payment' => $payTowardDebt,
            'debt_id' => isset($validated['debt_id']) ? (int) $validated['debt_id'] : null,
            'split_data' => $isSplit && ! empty($validated['split_data']) ? $validated['split_data'] : null,
        ]);

        $pendingImport->forceFill([
            'status' => 'confirmed',
            'transaction_id' => $transaction->id,
        ])->save();

        return response()->json(
            $transaction->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund'])
        );
    }

    public function confirmSplit(ConfirmSplitImportRequest $request, PlaidPendingImport $pendingImport): JsonResponse
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            return response()->json(['message' => 'This import is not pending confirmation.'], 422);
        }

        $user = $request->user();
        if ($user->family_id === null) {
            return response()->json(['message' => 'You must belong to a family to confirm imports.'], 403);
        }

        $validated = $request->validated();
        $lines = $validated['lines'];
        $transactionDate = $pendingImport->date->format('Y-m-d');

        foreach ($lines as $line) {
            $payload = $this->buildTransactionPayloadFromImportFields(
                $line,
                (float) $line['amount'],
                $transactionDate,
                $pendingImport,
            );

            try {
                $this->closedMonthGuard->assertTransactionPayloadOpen($user, $payload);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $createdTransactions = DB::transaction(function () use ($lines, $pendingImport, $user, $transactionDate): array {
            $created = [];
            $isFirst = true;

            foreach ($lines as $line) {
                $payload = $this->buildTransactionPayloadFromImportFields(
                    $line,
                    (float) $line['amount'],
                    $transactionDate,
                    $pendingImport,
                );

                $transaction = $this->transactionService->createTransaction($payload, $user);

                $resolvedTagFundId = $this->resolvedTagFundIdFromImportFields($line);

                $overrides = [
                    'plaid_pending_import_id' => $pendingImport->id,
                    'import_source' => 'plaid',
                ];

                if ($resolvedTagFundId !== null) {
                    $overrides['fund_id'] = $resolvedTagFundId;
                }

                if ($isFirst) {
                    $overrides['plaid_transaction_id'] = $pendingImport->plaid_transaction_id;
                    $isFirst = false;
                }

                $transaction->forceFill($overrides)->save();
                $created[] = $transaction;
            }

            $pendingImport->forceFill([
                'status' => 'confirmed',
                'transaction_id' => $created[0]->id,
            ])->save();

            return $created;
        });

        return response()->json([
            'count' => count($createdTransactions),
            'transactions' => $createdTransactions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function buildTransactionPayloadFromImportFields(
        array $fields,
        float $amount,
        string $transactionDate,
        PlaidPendingImport $pendingImport,
    ): array {
        $description = $fields['description'] ?? null;
        if ($description === null || $description === '') {
            $description = trim((string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? ''));
        }
        if ($description === '') {
            $description = 'Plaid import';
        }

        $isSplit = (bool) ($fields['is_split'] ?? false);
        $payTowardDebt = ($fields['type'] ?? '') === 'expense' && ! empty($fields['debt_id']);
        $resolvedAdvanceFundId = ($fields['type'] === 'expense' && ! $payTowardDebt) ? ($fields['advance_fund_id'] ?? null) : null;

        $payload = [
            'type' => $fields['type'],
            'amount' => $amount,
            'transaction_date' => $transactionDate,
            'description' => $description,
            'category_id' => $fields['category_id'],
            'is_split' => $isSplit,
            'split_data' => $isSplit && ! empty($fields['split_data']) ? $fields['split_data'] : null,
            'advance_fund_id' => $resolvedAdvanceFundId,
            'is_non_necessity' => (bool) ($fields['is_non_necessity'] ?? false),
        ];

        if (($fields['type'] ?? '') === 'expense' && $payTowardDebt) {
            $payload['debt_id'] = (int) $fields['debt_id'];
        }

        if (($fields['type'] ?? '') === 'income') {
            $payload['income_debt_mode'] = $fields['income_debt_mode'] ?? 'none';
            $payload['income_existing_debt_id'] = ($payload['income_debt_mode'] === 'existing') ? ($fields['income_existing_debt_id'] ?? null) : null;
            $payload['income_new_is_family_debt'] = ($payload['income_debt_mode'] === 'new') ? (bool) ($fields['income_new_is_family_debt'] ?? false) : false;
            $payload['income_new_is_interfamily'] = ($payload['income_debt_mode'] === 'new') ? (bool) ($fields['income_new_is_interfamily'] ?? false) : false;
            $payload['income_new_creditor_id'] = ($payload['income_debt_mode'] === 'new' && ($payload['income_new_is_interfamily'] ?? false))
                ? ($fields['income_new_creditor_id'] ?? null)
                : null;
            $payload['income_new_creditor_name'] = ($payload['income_debt_mode'] === 'new' && ! ($payload['income_new_is_interfamily'] ?? false))
                ? ($fields['income_new_creditor_name'] ?? null)
                : null;
            $payload['income_new_description'] = ($payload['income_debt_mode'] === 'new' && ! empty($fields['income_new_description']))
                ? $fields['income_new_description']
                : null;
            $payload['income_new_interest_enabled'] = ($payload['income_debt_mode'] === 'new') ? (bool) ($fields['income_new_interest_enabled'] ?? false) : false;
            $payload['income_new_interest_rate'] = ($payload['income_debt_mode'] === 'new' && ($payload['income_new_interest_enabled'] ?? false))
                ? ($fields['income_new_interest_rate'] ?? null)
                : null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function resolvedTagFundIdFromImportFields(array $fields): ?int
    {
        $payTowardDebt = ($fields['type'] ?? '') === 'expense' && ! empty($fields['debt_id']);
        $resolvedAdvanceFundId = ($fields['type'] === 'expense' && ! $payTowardDebt) ? ($fields['advance_fund_id'] ?? null) : null;

        if (! empty($fields['fund_id'])) {
            return (int) $fields['fund_id'];
        }

        return $resolvedAdvanceFundId !== null ? (int) $resolvedAdvanceFundId : null;
    }

    public function dismiss(Request $request, PlaidPendingImport $pendingImport): Response
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            abort(422, 'This import is not pending.');
        }

        $pendingImport->forceFill(['status' => 'dismissed', 'dismiss_source' => 'manual'])->save();

        $merchantRaw = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
        $key = $this->matchingService->normalizeMerchantKey($merchantRaw);
        $rule = PlaidMerchantRule::query()
            ->where('user_id', $pendingImport->user_id)
            ->where('merchant_key', $key)
            ->first();

        if ($rule !== null) {
            $this->matchingService->recordSeen($rule);
        }

        return response()->noContent();
    }

    public function dismissAsTransfer(Request $request, PlaidPendingImport $pendingImport): Response
    {
        if ($pendingImport->user_id !== auth()->id()) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            abort(422, 'This import is not pending.');
        }

        $pendingImport->forceFill(['status' => 'dismissed', 'dismiss_source' => 'manual'])->save();

        if ($request->boolean('learn')) {
            $merchantLabel = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');
            $this->matchingService->learnDismissRule($pendingImport->user_id, $merchantLabel);
        }

        return response()->noContent();
    }

    public function ledgerLinkCandidates(Request $request, PlaidPendingImport $pendingImport): JsonResponse
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            return response()->json(['message' => 'This import is not pending.'], 422);
        }

        $user = $request->user();
        if ($user->family_id === null) {
            return response()->json([
                'candidates' => [],
                'message' => 'You must belong to a family to link imports to existing transactions.',
            ]);
        }

        if ($pendingImport->is_transfer) {
            return response()->json([
                'candidates' => [],
                'message' => 'For transfer-style rows, use the Transfers tab.',
            ]);
        }

        $scored = $this->matchingService->findLedgerLinkCandidatesForPendingImport(
            $pendingImport,
            (int) $user->family_id,
        );

        $candidates = [];
        foreach ($scored as $row) {
            $tx = $row['transaction'];
            $candidates[] = [
                'id' => $tx->id,
                'date' => $tx->transaction_date?->format('Y-m-d'),
                'amount' => $tx->amount,
                'description' => $tx->description,
                'type' => $tx->type,
                'fund_id' => $tx->fund_id,
                'category' => $tx->category ? [
                    'id' => $tx->category->id,
                    'name' => $tx->category->name,
                    'icon' => $tx->category->icon,
                ] : null,
                'match_score' => round($row['score'], 4),
            ];
        }

        return response()->json(['candidates' => $candidates]);
    }

    public function linkToLedger(LinkPlaidPendingImportRequest $request, PlaidPendingImport $pendingImport): JsonResponse
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            return response()->json(['message' => 'This import is not pending.'], 422);
        }

        if ($pendingImport->is_transfer) {
            return response()->json(['message' => 'Use the Transfers tab for this row.'], 422);
        }

        $user = $request->user();
        if ($user->family_id === null) {
            return response()->json(['message' => 'You must belong to a family to link imports.'], 403);
        }

        $ledgerId = (int) $request->validated('transaction_id');

        try {
            $ledger = DB::transaction(function () use ($pendingImport, $ledgerId, $user): Transaction {
                $ledger = Transaction::query()->whereKey($ledgerId)->lockForUpdate()->first();
                if ($ledger === null) {
                    throw new InvalidArgumentException('Transaction not found.');
                }

                if ((int) $ledger->family_id !== (int) $user->family_id) {
                    throw new InvalidArgumentException('That transaction is not in your family.');
                }

                if (! $this->matchingService->canLinkPendingImportToLedger($pendingImport, $ledger)) {
                    throw new InvalidArgumentException(
                        'That transaction does not match this import (same amount and type within 60 days, and not already linked to Plaid).'
                    );
                }

                $plaidTid = (string) $pendingImport->plaid_transaction_id;
                if ($plaidTid !== '') {
                    $duplicate = Transaction::query()
                        ->where('family_id', $ledger->family_id)
                        ->where('plaid_transaction_id', $plaidTid)
                        ->whereKeyNot($ledger->id)
                        ->exists();

                    if ($duplicate) {
                        throw new InvalidArgumentException('This Plaid transaction is already linked to another ledger row.');
                    }
                }

                $merchantName = (string) ($pendingImport->merchant_name ?? $pendingImport->raw_name ?? '');

                $this->matchingService->learnFromConfirmation($user->id, $merchantName, [
                    'category_id' => $ledger->category_id,
                    'type' => $ledger->type,
                    'fund_id' => $ledger->fund_id,
                    'advance_fund_id' => $ledger->advance_fund_id,
                    'is_non_necessity' => (bool) $ledger->is_non_necessity,
                    'is_split' => (bool) $ledger->is_split,
                    'action' => 'categorize',
                    'description' => $ledger->description,
                    'is_debt_payment' => (bool) $ledger->is_debt_payment,
                    'debt_id' => $ledger->debt_id,
                    'split_data' => $ledger->is_split ? $ledger->split_data : null,
                ]);

                $ledger->forceFill([
                    'plaid_transaction_id' => $pendingImport->plaid_transaction_id,
                    'import_source' => 'plaid',
                ])->save();

                $pendingImport->forceFill([
                    'status' => 'confirmed',
                    'transaction_id' => $ledger->id,
                ])->save();

                return $ledger->fresh();
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not link this import. Try again.'], 500);
        }

        return response()->json(
            $ledger->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund'])
        );
    }

    public function calibrationData(Request $request, PlaidItem $plaidItem): JsonResponse
    {
        $this->assertPlaidItemOwned($request, $plaidItem);

        $data = $this->calibrationService->buildCalibrationMatches($plaidItem);

        $matched = [];
        foreach ($data['matched'] as $row) {
            $matched[] = [
                'plaid' => $row['plaid'],
                'score' => $row['score'],
                'ledger' => $this->serializeLedgerForCalibration($row['ledger']),
            ];
        }

        $unmatchedLedger = [];
        foreach ($data['unmatched_ledger'] as $tx) {
            $unmatchedLedger[] = $this->serializeLedgerForCalibration($tx);
        }

        return response()->json([
            'matched' => $matched,
            'unmatched_plaid' => $data['unmatched_plaid'],
            'unmatched_ledger' => $unmatchedLedger,
        ]);
    }

    public function applyCalibration(ApplyPlaidCalibrationRequest $request, PlaidItem $plaidItem): JsonResponse
    {
        $this->assertPlaidItemOwned($request, $plaidItem);

        $validated = $request->validated();

        $counts = $this->calibrationService->applyCalibrationResults(
            $plaidItem,
            $validated['confirmed_pairs'],
            $validated['import_as_new'],
        );

        return response()->json($counts);
    }

    public function syncMonth(Request $request, PlaidItem $plaidItem): JsonResponse
    {
        $this->assertPlaidItemOwned($request, $plaidItem);

        $start = Carbon::now()->startOfMonth()->toDateString();
        $end = Carbon::now()->endOfMonth()->toDateString();

        try {
            $rows = $this->syncService->fetchByDateRange($plaidItem, $start, $end);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Failed to fetch Plaid transactions for this month.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $counts = $this->syncService->ingestPlaidRowsAsPending($plaidItem, $rows);

        return response()->json($counts);
    }

    private function assertPlaidItemOwned(Request $request, PlaidItem $plaidItem): void
    {
        if ($plaidItem->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    /**
     * @return array{id: int, date: string|null, amount: mixed, description: string|null, type: string, fund_id: int|null, category: array{id: int, name: string, icon: string|null}|null}
     */
    private function serializeLedgerForCalibration(Transaction $transaction): array
    {
        $transaction->loadMissing('category');

        $category = $transaction->category;
        $categoryPayload = null;
        if ($category instanceof Category) {
            $categoryPayload = [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => $category->icon,
            ];
        }

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date?->format('Y-m-d'),
            'amount' => $transaction->amount,
            'description' => $transaction->description,
            'type' => $transaction->type,
            'fund_id' => $transaction->fund_id,
            'category' => $categoryPayload,
        ];
    }
}
