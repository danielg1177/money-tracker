<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\PlaidItem;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidTransactionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaidIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'plaid.client_id' => 'test_client',
            'plaid.secret' => 'test_secret',
            'plaid.base_url' => 'https://sandbox.plaid.com',
            'plaid.api_version' => '2020-09-14',
            'plaid.transactions_days_requested' => 90,
        ]);
    }

    private function familyUser(): User
    {
        $family = Family::factory()->create();

        return User::factory()->create(['family_id' => $family->id]);
    }

    public function test_link_token_returns_503_when_plaid_not_configured(): void
    {
        config(['plaid.client_id' => null, 'plaid.secret' => null]);

        $user = $this->familyUser();
        $this->actingAs($user)->getJson('/plaid/link-token')->assertStatus(503);
    }

    public function test_link_token_returns_token_from_plaid(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/link/token/create' => Http::response([
                'link_token' => 'link-sandbox-test',
            ], 200),
        ]);

        $user = $this->familyUser();
        $this->actingAs($user)->getJson('/plaid/link-token')
            ->assertOk()
            ->assertJson(['link_token' => 'link-sandbox-test']);
    }

    public function test_exchange_creates_item_and_returns_pull_payload_without_creating_ledger_transactions(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/item/public_token/exchange' => Http::response([
                'access_token' => 'access-sandbox-xxx',
                'item_id' => 'item-123',
            ], 200),
            'https://sandbox.plaid.com/item/get' => Http::response([
                'item' => ['institution_id' => 'ins_109508'],
            ], 200),
            'https://sandbox.plaid.com/institutions/get_by_id' => Http::response([
                'institution' => ['name' => 'First Platypus Bank'],
            ], 200),
            'https://sandbox.plaid.com/transactions/sync' => Http::response([
                'added' => [
                    [
                        'transaction_id' => 'txn-plaid-1',
                        'account_id' => 'acc1',
                        'amount' => 25.5,
                        'date' => '2026-05-02',
                        'pending' => false,
                        'name' => 'STORE',
                        'merchant_name' => 'Test Merchant',
                    ],
                ],
                'modified' => [],
                'removed' => [],
                'has_more' => false,
                'next_cursor' => 'cursor-next',
            ], 200),
        ]);

        $user = $this->familyUser();

        $response = $this->actingAs($user)->postJson('/plaid/exchange', [
            'public_token' => 'public-sandbox-xxx',
        ]);

        $response->assertCreated()
            ->assertJsonPath('pull.counts.added', 1)
            ->assertJsonPath('pull.added.0.transaction_id', 'txn-plaid-1');

        $this->assertDatabaseHas('plaid_items', [
            'user_id' => $user->id,
            'item_id' => 'item-123',
        ]);

        $this->assertSame(0, Transaction::query()->count());

        $this->assertDatabaseHas('plaid_pending_imports', [
            'plaid_transaction_id' => 'txn-plaid-1',
            'status' => 'pending',
        ]);
    }

    public function test_webhook_triggers_pull_for_matching_item_without_creating_ledger_transactions(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/transactions/sync' => Http::response([
                'added' => [
                    [
                        'transaction_id' => 'txn-webhook-1',
                        'account_id' => 'acc1',
                        'amount' => -10,
                        'date' => '2026-05-03',
                        'pending' => false,
                        'name' => 'DEPOSIT',
                    ],
                ],
                'modified' => [],
                'removed' => [],
                'has_more' => false,
                'next_cursor' => 'c2',
            ], 200),
        ]);

        $user = $this->familyUser();
        PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-wh',
            'access_token' => 'access-test',
        ]);

        $this->postJson('/plaid/webhook', [
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'SYNC_UPDATES_AVAILABLE',
            'item_id' => 'item-wh',
        ])->assertOk();

        $this->assertSame(0, Transaction::query()->count());

        $this->assertDatabaseHas('plaid_pending_imports', [
            'plaid_transaction_id' => 'txn-webhook-1',
            'status' => 'pending',
        ]);
    }

    public function test_fetch_by_date_range_paginates_transactions_get(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/transactions/get' => Http::sequence()
                ->push([
                    'transactions' => [
                        ['transaction_id' => 't1', 'amount' => 1, 'date' => '2026-01-05'],
                    ],
                    'total_transactions' => 2,
                ], 200)
                ->push([
                    'transactions' => [
                        ['transaction_id' => 't2', 'amount' => 2, 'date' => '2026-01-06'],
                    ],
                    'total_transactions' => 2,
                ], 200),
        ]);

        $user = $this->familyUser();
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-date-range',
            'access_token' => 'access-range',
        ]);

        $sync = app(PlaidTransactionSyncService::class);
        $rows = $sync->fetchByDateRange($item, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $rows);
        $this->assertSame('t1', $rows[0]['transaction_id']);
        $this->assertSame('t2', $rows[1]['transaction_id']);
    }

    public function test_user_without_family_can_exchange_when_plaid_is_configured(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/item/public_token/exchange' => Http::response([
                'access_token' => 'access-x',
                'item_id' => 'item-x',
            ], 200),
            'https://sandbox.plaid.com/item/get' => Http::response([
                'item' => ['institution_id' => null],
            ], 200),
            'https://sandbox.plaid.com/transactions/sync' => Http::response([
                'added' => [],
                'modified' => [],
                'removed' => [],
                'has_more' => false,
                'next_cursor' => 'c0',
            ], 200),
        ]);

        $user = User::factory()->create(['family_id' => null]);
        $this->actingAs($user)->postJson('/plaid/exchange', [
            'public_token' => 'public-x',
        ])->assertCreated();

        $this->assertDatabaseHas('plaid_items', [
            'user_id' => $user->id,
            'item_id' => 'item-x',
        ]);
    }

    public function test_pending_imports_count_only_returns_pending_row_count(): void
    {
        $user = $this->familyUser();
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-count',
            'access_token' => 'tok-count',
        ]);

        foreach (['tid-a', 'tid-b'] as $tid) {
            PlaidPendingImport::query()->create([
                'user_id' => $user->id,
                'plaid_item_id' => $item->id,
                'plaid_transaction_id' => $tid,
                'amount' => 1,
                'date' => '2026-05-01',
                'raw_name' => 'x',
                'suggested_type' => 'expense',
                'suggested_is_non_necessity' => false,
                'confidence_score' => 0,
                'status' => 'pending',
                'raw_payload' => [],
            ]);
        }

        PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'tid-dismissed',
            'amount' => 1,
            'date' => '2026-05-01',
            'raw_name' => 'x',
            'suggested_type' => 'expense',
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0,
            'status' => 'dismissed',
            'raw_payload' => [],
        ]);

        $this->actingAs($user)->getJson('/plaid/pending-imports?count_only=1')
            ->assertOk()
            ->assertJson(['count' => 2]);
    }
}
