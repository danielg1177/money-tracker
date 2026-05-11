<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaidImportTest extends TestCase
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

    private function createPlaidItem(User $user): PlaidItem
    {
        return PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-'.uniqid('', true),
            'access_token' => 'access-sandbox-test',
            'institution_id' => 'ins_test',
            'institution_name' => 'Test Bank',
            'transactions_cursor' => null,
        ]);
    }

    /**
     * @return array{import: PlaidPendingImport, category: Category}
     */
    private function createPendingImportForUser(User $user, string $plaidTxnId = 'txn-pending-1', bool $isTransfer = false): array
    {
        $item = $this->createPlaidItem($user);
        $category = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $import = PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => $plaidTxnId,
            'plaid_account_id' => 'acc1',
            'amount' => 42.5,
            'date' => now()->toDateString(),
            'merchant_name' => 'Corner Store',
            'raw_name' => 'CORNER STORE #1',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.5,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => $isTransfer,
        ]);

        return ['import' => $import, 'category' => $category];
    }

    public function test_merchant_rule_is_not_auto_create_eligible_until_three_confirmations_at_eighty_percent(): void
    {
        $user = $this->familyUser();

        $almost = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'alpha',
            'category_id' => null,
            'type' => 'expense',
            'confirmation_count' => 2,
            'total_seen_count' => 2,
        ]);
        $this->assertFalse($almost->isAutoCreateEligible());

        $lowConfidence = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'beta',
            'category_id' => null,
            'type' => 'expense',
            'confirmation_count' => 3,
            'total_seen_count' => 5,
        ]);
        $this->assertEqualsWithDelta(0.6, $lowConfidence->confidenceScore(), 0.0001);
        $this->assertFalse($lowConfidence->isAutoCreateEligible());

        $eligible = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'gamma',
            'category_id' => null,
            'type' => 'expense',
            'confirmation_count' => 3,
            'total_seen_count' => 3,
        ]);
        $this->assertEqualsWithDelta(1.0, $eligible->confidenceScore(), 0.0001);
        $this->assertTrue($eligible->isAutoCreateEligible());

        $exactEighty = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'delta',
            'category_id' => null,
            'type' => 'expense',
            'confirmation_count' => 4,
            'total_seen_count' => 5,
        ]);
        $this->assertEqualsWithDelta(0.8, $exactEighty->confidenceScore(), 0.0001);
        $this->assertTrue($exactEighty->isAutoCreateEligible());
    }

    public function test_find_ledger_match_returns_transaction_when_amount_date_and_name_align(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $transaction = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 18.0,
            'description' => 'Fresh Market weekly shop',
            'transaction_date' => '2026-05-10',
        ]);

        $plaidRow = [
            'amount' => 18.0,
            'date' => '2026-05-10',
            'merchant_name' => 'Fresh Market',
        ];

        $match = app(PlaidMatchingService::class)->findLedgerMatch($plaidRow, $family->id);

        $this->assertNotNull($match);
        $this->assertTrue($match->is($transaction));
    }

    public function test_find_ledger_match_returns_null_when_amount_or_date_do_not_align(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 10,
            'description' => 'Same Merchant Here',
            'transaction_date' => '2026-05-10',
        ]);

        $this->assertNull(app(PlaidMatchingService::class)->findLedgerMatch([
            'amount' => 99,
            'date' => '2026-05-10',
            'merchant_name' => 'Same Merchant Here',
        ], $family->id));

        $this->assertNull(app(PlaidMatchingService::class)->findLedgerMatch([
            'amount' => 10,
            'date' => '2026-05-12',
            'merchant_name' => 'Same Merchant Here',
        ], $family->id));
    }

    public function test_confirm_pending_import_creates_ledger_transaction_and_marks_import_confirmed(): void
    {
        $user = $this->familyUser();
        ['import' => $import, 'category' => $category] = $this->createPendingImportForUser($user);

        $response = $this->actingAs($user)->postJson(
            "/plaid/pending-imports/{$import->id}/confirm",
            [
                'category_id' => $category->id,
                'type' => 'expense',
            ]
        );

        $response->assertOk();
        $this->assertDatabaseHas('plaid_pending_imports', [
            'id' => $import->id,
            'status' => 'confirmed',
        ]);

        $import->refresh();
        $this->assertNotNull($import->transaction_id);

        $this->assertDatabaseHas('transactions', [
            'id' => $import->transaction_id,
            'family_id' => $user->family_id,
            'plaid_transaction_id' => $import->plaid_transaction_id,
            'import_source' => 'plaid',
        ]);
    }

    public function test_dismiss_pending_import_sets_status_to_dismissed(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-dismiss-1');

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/dismiss")
            ->assertNoContent();

        $this->assertDatabaseHas('plaid_pending_imports', [
            'id' => $import->id,
            'status' => 'dismissed',
        ]);
    }

    public function test_dismiss_as_transfer_sets_dismissed_without_learn_does_not_create_merchant_rule(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-transfer-dismiss-1');

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/dismiss-as-transfer")
            ->assertNoContent();

        $import->refresh();
        $this->assertSame('dismissed', $import->status);

        $key = app(PlaidMatchingService::class)->normalizeMerchantKey(
            (string) ($import->merchant_name ?? $import->raw_name ?? '')
        );

        $this->assertDatabaseMissing('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
        ]);
    }

    public function test_dismiss_as_transfer_with_learn_sets_merchant_rule_action_dismiss_and_increments_total_seen(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-transfer-dismiss-learn-1');

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/dismiss-as-transfer?learn=true")
            ->assertNoContent();

        $key = app(PlaidMatchingService::class)->normalizeMerchantKey(
            (string) ($import->merchant_name ?? $import->raw_name ?? '')
        );

        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
            'action' => 'dismiss',
            'total_seen_count' => 1,
            'confirmation_count' => 0,
        ]);
    }

    public function test_pending_imports_index_returns_only_authenticated_users_pending_rows(): void
    {
        $userA = $this->familyUser();
        $userB = $this->familyUser();

        ['import' => $importRegular] = $this->createPendingImportForUser($userA, 'txn-a-1', false);
        ['import' => $importTransfer] = $this->createPendingImportForUser($userA, 'txn-a-2', true);
        $this->createPendingImportForUser($userB, 'txn-b-1');

        $response = $this->actingAs($userA)->getJson('/plaid/pending-imports');

        $response->assertOk();
        $pendingIds = collect($response->json('pending'))->pluck('id')->all();
        $transferIds = collect($response->json('transfers'))->pluck('id')->all();
        $this->assertContains($importRegular->id, $pendingIds);
        $this->assertContains($importTransfer->id, $transferIds);
        $this->assertCount(1, $pendingIds);
        $this->assertCount(1, $transferIds);
    }

    public function test_confirm_returns_403_when_pending_import_belongs_to_another_user(): void
    {
        $userA = $this->familyUser();
        $userB = $this->familyUser();
        ['import' => $importB] = $this->createPendingImportForUser($userB);

        $categoryA = Category::factory()->create([
            'family_id' => $userA->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->app->make('router')->bind('pendingImport', function (string $value): PlaidPendingImport {
            return PlaidPendingImport::query()->whereKey($value)->firstOrFail();
        });

        $this->actingAs($userA)->postJson(
            "/plaid/pending-imports/{$importB->id}/confirm",
            [
                'category_id' => $categoryA->id,
                'type' => 'expense',
            ]
        )->assertForbidden();
    }

    public function test_plaid_daily_sync_command_runs_with_mocked_plaid_http(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/transactions/sync' => Http::response([
                'added' => [],
                'modified' => [],
                'removed' => [],
                'has_more' => false,
                'next_cursor' => null,
            ], 200),
        ]);

        $user = $this->familyUser();
        $item = $this->createPlaidItem($user);

        $exitCode = Artisan::call('plaid:daily-sync', ['--item' => (string) $item->id]);

        $this->assertSame(0, $exitCode);
    }

    public function test_ledger_link_candidates_returns_matching_family_transactions(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-cand-1');

        Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'Corner Store weekly',
            'transaction_date' => $import->date->format('Y-m-d'),
        ]);

        $response = $this->actingAs($user)->getJson("/plaid/pending-imports/{$import->id}/ledger-candidates");

        $response->assertOk();
        $ids = collect($response->json('candidates'))->pluck('id')->all();
        $this->assertNotEmpty($ids);
    }

    public function test_link_pending_import_to_existing_ledger_row_sets_plaid_id_and_learns_merchant_rule(): void
    {
        $user = $this->familyUser();
        ['import' => $import, 'category' => $category] = $this->createPendingImportForUser($user, 'txn-link-1');

        $ledger = Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'Corner Store purchase',
            'transaction_date' => $import->date->format('Y-m-d'),
        ]);

        $response = $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/link", [
            'transaction_id' => $ledger->id,
        ]);

        $response->assertOk();
        $ledger->refresh();
        $this->assertSame('txn-link-1', $ledger->plaid_transaction_id);
        $this->assertSame('plaid', $ledger->import_source);

        $import->refresh();
        $this->assertSame('confirmed', $import->status);
        $this->assertSame($ledger->id, $import->transaction_id);

        $key = app(PlaidMatchingService::class)->normalizeMerchantKey(
            (string) ($import->merchant_name ?? $import->raw_name ?? '')
        );
        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
            'category_id' => $category->id,
            'confirmation_count' => 1,
        ]);
    }

    public function test_link_pending_import_returns_422_when_amounts_differ(): void
    {
        $user = $this->familyUser();
        ['import' => $import, 'category' => $category] = $this->createPendingImportForUser($user, 'txn-link-bad');

        $ledger = Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 99.99,
            'description' => 'Other',
            'transaction_date' => $import->date->format('Y-m-d'),
        ]);

        $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/link", [
            'transaction_id' => $ledger->id,
        ])->assertStatus(422);
    }

    public function test_link_pending_import_returns_422_when_plaid_id_already_on_another_row(): void
    {
        $user = $this->familyUser();
        ['import' => $import, 'category' => $category] = $this->createPendingImportForUser($user, 'txn-dup-plaid');

        Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'Already linked row',
            'transaction_date' => $import->date->format('Y-m-d'),
            'plaid_transaction_id' => 'txn-dup-plaid',
            'import_source' => 'plaid',
        ]);

        $ledger = Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'Target row',
            'transaction_date' => $import->date->format('Y-m-d'),
        ]);

        $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/link", [
            'transaction_id' => $ledger->id,
        ])->assertStatus(422);
    }
}
