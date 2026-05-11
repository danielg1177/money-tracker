<?php

namespace App\Http\Controllers;

use App\Models\PlaidItem;
use App\Services\PlaidTransactionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Acknowledges Plaid webhooks and runs a cursor-advancing pull only (no ledger writes).
 */
class PlaidWebhookController extends Controller
{
    public function __invoke(Request $request, PlaidTransactionSyncService $syncService): JsonResponse
    {
        $payload = $request->all();

        if (($payload['webhook_type'] ?? '') !== 'TRANSACTIONS') {
            return response()->json(['ok' => true]);
        }

        $itemId = $payload['item_id'] ?? null;
        if (! is_string($itemId) || $itemId === '') {
            return response()->json(['ok' => true]);
        }

        $item = PlaidItem::query()->where('item_id', $itemId)->first();
        if ($item) {
            try {
                $syncService->syncItem($item);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true]);
    }
}
