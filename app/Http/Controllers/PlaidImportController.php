<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyPlaidCalibrationRequest;
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

            return response()->json([
                'count' => $count,
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

        $recentlyAutoCreated = PlaidPendingImport::query()
            ->where('user_id', $user->id)
            ->where('status', 'auto_created')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return response()->json([
            'pending' => $pending,
            'transfers' => $transfers,
            'recently_auto_created' => $recentlyAutoCreated,
        ]);
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
            'is_split' => false,
            'split_data' => null,
            'advance_fund_id' => $validated['advance_fund_id'] ?? null,
            'is_non_necessity' => (bool) ($validated['is_non_necessity'] ?? false),
        ];

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
        ]);

        $pendingImport->forceFill([
            'status' => 'confirmed',
            'transaction_id' => $transaction->id,
        ])->save();

        return response()->json(
            $transaction->load(['user', 'category', 'splits.user', 'debt.creditor', 'debt.debtor', 'debt.fund'])
        );
    }

    public function dismiss(Request $request, PlaidPendingImport $pendingImport): Response
    {
        if ($pendingImport->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($pendingImport->status !== 'pending') {
            abort(422, 'This import is not pending.');
        }

        $pendingImport->forceFill(['status' => 'dismissed'])->save();

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

        $pendingImport->status = 'dismissed';
        $pendingImport->save();

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
