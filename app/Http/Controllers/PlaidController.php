<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExchangePlaidTokenRequest;
use App\Models\PlaidItem;
use App\Services\PlaidClient;
use App\Services\PlaidTransactionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PlaidController extends Controller
{
    public function linkToken(Request $request, PlaidClient $plaidClient): JsonResponse
    {
        if (! PlaidClient::isConfigured()) {
            return response()->json([
                'message' => 'Plaid is not configured. Add PLAID_CLIENT_ID and PLAID_SECRET to your environment.',
            ], 503);
        }

        $user = $request->user();

        $json = $plaidClient->post('/link/token/create', [
            'user' => [
                'client_user_id' => (string) $user->id,
            ],
            'client_name' => (string) config('app.name'),
            'products' => ['transactions'],
            'country_codes' => ['US'],
            'language' => 'en',
            'transactions' => [
                'days_requested' => config('plaid.transactions_days_requested'),
            ],
        ]);

        return response()->json([
            'link_token' => $json['link_token'] ?? null,
        ]);
    }

    public function exchange(
        ExchangePlaidTokenRequest $request,
        PlaidClient $plaidClient,
        PlaidTransactionSyncService $syncService,
    ): JsonResponse {
        if (! PlaidClient::isConfigured()) {
            return response()->json([
                'message' => 'Plaid is not configured.',
            ], 503);
        }

        $user = $request->user();

        $json = $plaidClient->post('/item/public_token/exchange', [
            'public_token' => $request->validated('public_token'),
        ]);

        $accessToken = $json['access_token'] ?? null;
        $itemId = $json['item_id'] ?? null;

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($itemId) || $itemId === '') {
            return response()->json(['message' => 'Unexpected Plaid exchange response.'], 502);
        }

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => $itemId,
            'access_token' => $accessToken,
        ]);

        try {
            $syncService->hydrateInstitution($item);
        } catch (Throwable $e) {
            report($e);
        }

        $pull = [
            'counts' => ['added' => 0, 'modified' => 0, 'removed' => 0],
            'added' => [],
            'modified' => [],
            'removed' => [],
            'accounts' => [],
        ];

        try {
            $pull = $syncService->syncItem($item->fresh());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Bank linked, but the first data pull failed. Try “Pull now” in a moment.',
                'item' => $this->serializePlaidItem($item->fresh()),
                'pull' => $pull,
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'item' => $this->serializePlaidItem($item->fresh()),
            'pull' => $pull,
        ], 201);
    }

    public function items(Request $request): JsonResponse
    {
        $rows = PlaidItem::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get([
                'id',
                'item_id',
                'institution_id',
                'institution_name',
                'created_at',
                'updated_at',
            ]);

        return response()->json($rows);
    }

    public function sync(Request $request, PlaidItem $plaidItem, PlaidTransactionSyncService $syncService): JsonResponse
    {
        if ($plaidItem->user_id !== $request->user()->id) {
            abort(403);
        }

        try {
            $pull = $syncService->syncItem($plaidItem);

            return response()->json([
                'pull' => $pull,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Pull failed.',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    public function destroy(Request $request, PlaidItem $plaidItem, PlaidClient $plaidClient): JsonResponse
    {
        if ($plaidItem->user_id !== $request->user()->id) {
            abort(403);
        }

        try {
            $plaidClient->post('/item/remove', [
                'access_token' => $plaidItem->access_token,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        $plaidItem->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlaidItem(PlaidItem $item): array
    {
        return [
            'id' => $item->id,
            'item_id' => $item->item_id,
            'institution_id' => $item->institution_id,
            'institution_name' => $item->institution_name,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }
}
