<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryUserDefault;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidMatchingService;
use App\Services\PlaidTransactionSyncService;
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

    public function test_confirm_pending_import_with_debt_id_creates_debt_payment_expense(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
        ]);
        $item = $this->createPlaidItem($debtor);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $import = PlaidPendingImport::query()->create([
            'user_id' => $debtor->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-debt-pay-import-1',
            'plaid_account_id' => 'acc1',
            'amount' => 25.0,
            'date' => now()->toDateString(),
            'merchant_name' => 'Debt Pay',
            'raw_name' => 'DEBT PAY',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.5,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);

        $this->actingAs($debtor)->postJson(
            "/plaid/pending-imports/{$import->id}/confirm",
            [
                'category_id' => $category->id,
                'type' => 'expense',
                'debt_id' => $debt->id,
                'is_split' => false,
            ]
        )->assertOk();

        $debt->refresh();
        $this->assertSame('75.00', (string) $debt->balance);

        $import->refresh();
        $this->assertSame('confirmed', $import->status);
        $expense = Transaction::query()->findOrFail($import->transaction_id);
        $this->assertTrue($expense->is_debt_payment);
        $this->assertSame($debt->id, (int) $expense->debt_id);
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

    public function test_ledger_link_candidates_returns_matching_transactions_for_importer(): void
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

    public function test_ledger_link_candidates_excludes_other_family_members_transactions(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $item = $this->createPlaidItem($userA);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $import = PlaidPendingImport::query()->create([
            'user_id' => $userA->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-cand-owner-scope',
            'plaid_account_id' => 'acc1',
            'amount' => 42.5,
            'date' => now()->toDateString(),
            'merchant_name' => 'Shared Merchant',
            'raw_name' => 'SHARED MERCHANT',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.5,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);
        $dateStr = $import->date->format('Y-m-d');

        Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $userB->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'Spouse entry',
            'transaction_date' => $dateStr,
        ]);

        $own = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'My manual entry',
            'transaction_date' => $dateStr,
        ]);

        $response = $this->actingAs($userA)->getJson("/plaid/pending-imports/{$import->id}/ledger-candidates");
        $response->assertOk();
        $ids = collect($response->json('candidates'))->pluck('id')->all();
        $this->assertContains($own->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_link_pending_import_rejects_ledger_row_not_owned_by_importer(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $item = $this->createPlaidItem($userA);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $import = PlaidPendingImport::query()->create([
            'user_id' => $userA->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-link-spouse-row',
            'plaid_account_id' => 'acc1',
            'amount' => 42.5,
            'date' => now()->toDateString(),
            'merchant_name' => 'Store',
            'raw_name' => 'STORE',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.5,
            'status' => 'pending',
            'transaction_id' => null,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);

        $ledgerB = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $userB->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.5,
            'description' => 'Spouse manual',
            'transaction_date' => $import->date->format('Y-m-d'),
        ]);

        $this->actingAs($userA)->postJson("/plaid/pending-imports/{$import->id}/link", [
            'transaction_id' => $ledgerB->id,
        ])->assertStatus(422)->assertJsonValidationErrors('transaction_id');
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

    public function test_plaid_auto_create_applies_category_split_default_equal_shares(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        User::factory()->create(['family_id' => $family->id]);

        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
            'is_split_default' => true,
            'split_default' => [
                ['user_id' => 1, 'share_percentage' => 50],
                ['user_id' => 2, 'share_percentage' => 50],
            ],
        ]);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'splitcat cafe',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_split' => false,
            'confirmation_count' => 4,
            'total_seen_count' => 4,
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-auto-split',
            'access_token' => 'tok',
        ]);

        $sync = app(PlaidTransactionSyncService::class);
        $counts = $sync->ingestPlaidRowsAsPending($item, [[
            'transaction_id' => 'txn-auto-split-cat',
            'amount' => 20,
            'date' => '2026-05-10',
            'merchant_name' => 'SplitCat Cafe',
        ]]);

        $this->assertSame(1, $counts['auto_created']);
        $tx = Transaction::query()->where('plaid_transaction_id', 'txn-auto-split-cat')->first();
        $this->assertNotNull($tx);
        $this->assertTrue($tx->is_split);
        $this->assertCount(2, $tx->splits);
    }

    public function test_plaid_auto_create_applies_category_user_default_advance_over_merchant_rule(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $fundA = Fund::factory()->create(['user_id' => $user->id, 'family_id' => null]);
        $fundB = Fund::factory()->create(['user_id' => $user->id, 'family_id' => null]);

        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        CategoryUserDefault::query()->create([
            'category_id' => $category->id,
            'user_id' => $user->id,
            'advance_fund_id' => $fundA->id,
            'is_non_necessity_default' => false,
        ]);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'advance cat',
            'category_id' => $category->id,
            'type' => 'expense',
            'advance_fund_id' => $fundB->id,
            'confirmation_count' => 4,
            'total_seen_count' => 4,
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-auto-adv',
            'access_token' => 'tok',
        ]);

        app(PlaidTransactionSyncService::class)->ingestPlaidRowsAsPending($item, [[
            'transaction_id' => 'txn-auto-adv',
            'amount' => 15,
            'date' => '2026-05-11',
            'merchant_name' => 'Advance Cat',
        ]]);

        $tx = Transaction::query()->where('plaid_transaction_id', 'txn-auto-adv')->firstOrFail();
        $this->assertSame($fundA->id, (int) $tx->advance_fund_id);
        $this->assertSame($fundA->id, (int) $tx->fund_id);
    }

    public function test_approve_auto_created_sets_reviewed_at_and_row_leaves_auto_created_queue(): void
    {
        $user = $this->familyUser();
        $item = $this->createPlaidItem($user);
        $category = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $transaction = Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 30,
            'transaction_date' => '2026-05-20',
            'plaid_transaction_id' => 'txn-approve-queue',
            'import_source' => 'plaid',
        ]);

        $import = PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-approve-queue',
            'plaid_account_id' => 'acc1',
            'amount' => 30,
            'date' => '2026-05-20',
            'merchant_name' => 'Queue Test',
            'raw_name' => 'QUEUE TEST',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.9,
            'status' => 'auto_created',
            'transaction_id' => $transaction->id,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);

        $this->actingAs($user)->getJson('/plaid/pending-imports')
            ->assertOk()
            ->assertJsonCount(1, 'auto_created');

        $this->actingAs($user)->postJson("/plaid/pending-imports/{$import->id}/approve-auto-created", [])
            ->assertNoContent();

        $import->refresh();
        $this->assertNotNull($import->reviewed_at);

        $this->actingAs($user)->getJson('/plaid/pending-imports')
            ->assertOk()
            ->assertJsonCount(0, 'auto_created');

        $this->actingAs($user)->getJson('/plaid/pending-imports?count_only=1')
            ->assertOk()
            ->assertJson(['auto_created_count' => 0]);
    }

    public function test_learn_from_confirmation_saves_description(): void
    {
        $user = $this->familyUser();
        $service = app(PlaidMatchingService::class);

        $service->learnFromConfirmation($user->id, 'Coffee Shop', [
            'type' => 'expense',
            'action' => 'categorize',
            'description' => 'My Coffee Spot',
        ]);

        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $service->normalizeMerchantKey('Coffee Shop'),
            'description' => 'My Coffee Spot',
        ]);
    }

    public function test_learn_from_confirmation_saves_is_debt_payment(): void
    {
        $user = $this->familyUser();
        $service = app(PlaidMatchingService::class);

        $service->learnFromConfirmation($user->id, 'Loan Payment', [
            'type' => 'expense',
            'action' => 'categorize',
            'is_debt_payment' => true,
        ]);

        $rule = PlaidMerchantRule::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $service->normalizeMerchantKey('Loan Payment'))
            ->firstOrFail();

        $this->assertTrue((bool) $rule->is_debt_payment);
    }

    public function test_learn_from_confirmation_saves_split_data(): void
    {
        $user = $this->familyUser();
        $service = app(PlaidMatchingService::class);

        $splitData = [
            ['user_id' => 1, 'share_percentage' => 70.0],
            ['user_id' => 2, 'share_percentage' => 30.0],
        ];

        $service->learnFromConfirmation($user->id, 'Grocery Store', [
            'type' => 'expense',
            'action' => 'categorize',
            'split_data' => $splitData,
        ]);

        $rule = PlaidMerchantRule::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $service->normalizeMerchantKey('Grocery Store'))
            ->firstOrFail();

        $this->assertIsArray($rule->split_data);
        $this->assertEqualsWithDelta(70.0, (float) $rule->split_data[0]['share_percentage'], 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $rule->split_data[1]['share_percentage'], 0.01);
    }

    public function test_get_suggestion_returns_new_fields(): void
    {
        $user = $this->familyUser();
        $service = app(PlaidMatchingService::class);

        $merchantKey = $service->normalizeMerchantKey('Test Shop');
        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => $merchantKey,
            'type' => 'expense',
            'description' => 'Test Shop',
            'is_debt_payment' => false,
            'split_data' => [['user_id' => 1, 'share_percentage' => 100.0]],
            'confirmation_count' => 1,
            'total_seen_count' => 1,
            'action' => 'categorize',
        ]);

        $suggestion = $service->getSuggestion([
            'merchant_name' => 'Test Shop',
            'amount' => 10.0,
        ], $user->id);

        $this->assertSame('Test Shop', $suggestion['description']);
        $this->assertFalse($suggestion['is_debt_payment']);
        $this->assertIsArray($suggestion['split_data']);
        $this->assertEqualsWithDelta(100.0, (float) $suggestion['split_data'][0]['share_percentage'], 0.01);
    }

    public function test_get_suggestion_returns_defaults_when_no_rule(): void
    {
        $user = $this->familyUser();

        $suggestion = app(PlaidMatchingService::class)->getSuggestion([
            'merchant_name' => 'Unknown Merchant No Rule',
            'amount' => 25.0,
        ], $user->id);

        $this->assertNull($suggestion['description']);
        $this->assertFalse($suggestion['is_debt_payment']);
        $this->assertNull($suggestion['split_data']);
    }

    public function test_auto_create_skipped_when_rule_has_is_debt_payment(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'debt pay merchant',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_debt_payment' => true,
            'confirmation_count' => 3,
            'total_seen_count' => 3,
            'action' => 'categorize',
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-debt-skip',
            'access_token' => 'tok',
        ]);

        app(PlaidTransactionSyncService::class)->ingestPlaidRowsAsPending($item, [[
            'transaction_id' => 'txn-debt-skip-1',
            'amount' => 50.0,
            'date' => '2026-05-10',
            'merchant_name' => 'Debt Pay Merchant',
        ]]);

        $this->assertDatabaseMissing('transactions', [
            'plaid_transaction_id' => 'txn-debt-skip-1',
        ]);

        $import = PlaidPendingImport::query()
            ->where('plaid_transaction_id', 'txn-debt-skip-1')
            ->firstOrFail();

        $this->assertSame('pending', $import->status);
        $this->assertTrue((bool) $import->suggested_is_debt_payment);
    }

    public function test_auto_create_uses_learned_split_data_percentages(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $otherUser = User::factory()->create(['family_id' => $family->id]);

        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'splitlearn shop',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user->id, 'share_percentage' => 70.0],
                ['user_id' => $otherUser->id, 'share_percentage' => 30.0],
            ],
            'confirmation_count' => 3,
            'total_seen_count' => 3,
            'action' => 'categorize',
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-split-learn',
            'access_token' => 'tok',
        ]);

        $counts = app(PlaidTransactionSyncService::class)->ingestPlaidRowsAsPending($item, [[
            'transaction_id' => 'txn-split-learn-1',
            'amount' => 100.0,
            'date' => '2026-05-10',
            'merchant_name' => 'SplitLearn Shop',
        ]]);

        $this->assertSame(1, $counts['auto_created']);

        $tx = Transaction::query()->where('plaid_transaction_id', 'txn-split-learn-1')->firstOrFail();
        $this->assertTrue((bool) $tx->is_split);
        $this->assertCount(2, $tx->splits);

        $splits = $tx->splits->keyBy('user_id');
        $this->assertEqualsWithDelta(70.0, (float) $splits[$user->id]->share_percentage, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $splits[$otherUser->id]->share_percentage, 0.01);
    }

    public function test_auto_create_uses_learned_description(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'merchant raw',
            'category_id' => $category->id,
            'type' => 'expense',
            'description' => 'My Preferred Name',
            'confirmation_count' => 3,
            'total_seen_count' => 3,
            'action' => 'categorize',
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-desc-learn',
            'access_token' => 'tok',
        ]);

        app(PlaidTransactionSyncService::class)->ingestPlaidRowsAsPending($item, [[
            'transaction_id' => 'txn-desc-learn-1',
            'amount' => 20.0,
            'date' => '2026-05-10',
            'merchant_name' => 'MERCHANT RAW',
        ]]);

        $tx = Transaction::query()->where('plaid_transaction_id', 'txn-desc-learn-1')->firstOrFail();
        $this->assertSame('My Preferred Name', $tx->description);
    }

    public function test_pending_import_suggested_fields_populated_from_rule(): void
    {
        $user = $this->familyUser();
        $service = app(PlaidMatchingService::class);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => $service->normalizeMerchantKey('Foo Bar'),
            'type' => 'expense',
            'description' => 'Foo',
            'is_debt_payment' => true,
            'split_data' => [['user_id' => $user->id, 'share_percentage' => 100.0]],
            'confirmation_count' => 1,
            'total_seen_count' => 1,
            'action' => 'categorize',
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-suggested-fields',
            'access_token' => 'tok',
        ]);

        app(PlaidTransactionSyncService::class)->ingestPlaidRowsAsPending($item, [[
            'transaction_id' => 'txn-suggested-fields-1',
            'amount' => 15.0,
            'date' => '2026-05-10',
            'merchant_name' => 'Foo Bar',
        ]]);

        $import = PlaidPendingImport::query()
            ->where('plaid_transaction_id', 'txn-suggested-fields-1')
            ->firstOrFail();

        $this->assertSame('Foo', $import->suggested_description);
        $this->assertTrue((bool) $import->suggested_is_debt_payment);
        $this->assertIsArray($import->suggested_split_data);
        $this->assertEqualsWithDelta(100.0, (float) $import->suggested_split_data[0]['share_percentage'], 0.01);
    }

    public function test_confirm_endpoint_learns_description(): void
    {
        $user = $this->familyUser();
        ['import' => $import, 'category' => $category] = $this->createPendingImportForUser($user, 'txn-confirm-desc-1');

        $this->actingAs($user)->postJson(
            "/plaid/pending-imports/{$import->id}/confirm",
            [
                'category_id' => $category->id,
                'type' => 'expense',
                'description' => 'My Confirmed Description',
                'is_debt_payment' => false,
            ]
        )->assertOk();

        $key = app(PlaidMatchingService::class)->normalizeMerchantKey(
            (string) ($import->merchant_name ?? $import->raw_name ?? '')
        );

        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => $key,
            'description' => 'My Confirmed Description',
        ]);
    }

    public function test_pending_imports_index_includes_nested_funds_on_auto_created_transaction(): void
    {
        $user = $this->familyUser();
        $item = $this->createPlaidItem($user);
        $category = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
        ]);
        $transaction = Transaction::factory()->create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 12.34,
            'transaction_date' => '2026-05-21',
            'plaid_transaction_id' => 'txn-nested-fund',
            'import_source' => 'plaid',
            'advance_fund_id' => $fund->id,
            'fund_id' => $fund->id,
        ]);

        PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-nested-fund',
            'plaid_account_id' => 'acc1',
            'amount' => 12.34,
            'date' => '2026-05-21',
            'merchant_name' => 'Nested Fund Shop',
            'raw_name' => 'NESTED',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_fund_id' => null,
            'suggested_advance_fund_id' => null,
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0.85,
            'status' => 'auto_created',
            'transaction_id' => $transaction->id,
            'raw_payload' => [],
            'is_transfer' => false,
        ]);

        $res = $this->actingAs($user)->getJson('/plaid/pending-imports')->assertOk()->json();
        $row = collect($res['auto_created'])->first();
        $this->assertNotNull($row);
        $this->assertSame($transaction->id, (int) $row['transaction']['id']);
        $advance = $row['transaction']['advance_fund'] ?? $row['transaction']['advanceFund'] ?? null;
        $tag = $row['transaction']['fund'] ?? null;
        $this->assertIsArray($advance);
        $this->assertSame($fund->name, $advance['name']);
        $this->assertIsArray($tag);
        $this->assertSame($fund->name, $tag['name']);
    }

    public function test_confirm_split_creates_multiple_transactions_from_pending_import(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-split-1');
        $categoryA = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $categoryB = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    ['amount' => 20.00, 'type' => 'expense', 'category_id' => $categoryA->id, 'description' => 'Office supplies'],
                    ['amount' => 22.50, 'type' => 'expense', 'category_id' => $categoryB->id, 'description' => 'Personal'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('count', 2);

        $import->refresh();
        $this->assertSame('confirmed', $import->status);
        $this->assertNotNull($import->transaction_id);

        $transactions = Transaction::query()
            ->where('plaid_pending_import_id', $import->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertSame($import->plaid_transaction_id, $transactions[0]->plaid_transaction_id);
        $this->assertNull($transactions[1]->plaid_transaction_id);

        foreach ($transactions as $tx) {
            $this->assertSame('plaid', $tx->import_source);
            $this->assertSame($import->id, $tx->plaid_pending_import_id);
        }

        $this->assertSame((float) 20.00, (float) $transactions[0]->amount);
        $this->assertSame((float) 22.50, (float) $transactions[1]->amount);
        $this->assertSame('Office supplies', $transactions[0]->description);
        $this->assertSame('Personal', $transactions[1]->description);
    }

    public function test_confirm_split_requires_at_least_two_lines(): void
    {
        $user = $this->familyUser();
        ['import' => $import, 'category' => $category] = $this->createPendingImportForUser($user, 'txn-split-2');

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    ['amount' => 42.50, 'type' => 'expense', 'category_id' => $category->id],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_confirm_split_rejects_when_amounts_do_not_sum_to_import_total(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-split-3');
        $categoryA = Category::factory()->create(['family_id' => $user->family_id, 'is_expense' => true, 'is_income' => false]);
        $categoryB = Category::factory()->create(['family_id' => $user->family_id, 'is_expense' => true, 'is_income' => false]);

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    ['amount' => 10.00, 'type' => 'expense', 'category_id' => $categoryA->id],
                    ['amount' => 10.00, 'type' => 'expense', 'category_id' => $categoryB->id],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.lines.0', fn ($v) => str_contains($v, '42.50'));
    }

    public function test_confirm_split_is_rejected_for_wrong_user(): void
    {
        $owner = $this->familyUser();
        $other = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($owner, 'txn-split-4');

        $this->actingAs($other)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    ['amount' => 21.25, 'type' => 'expense', 'category_id' => 1],
                    ['amount' => 21.25, 'type' => 'expense', 'category_id' => 1],
                ],
            ])
            ->assertNotFound();
    }

    public function test_confirm_split_is_rejected_when_import_is_not_pending(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-split-5');
        $categoryA = Category::factory()->create(['family_id' => $user->family_id, 'is_expense' => true, 'is_income' => false]);
        $categoryB = Category::factory()->create(['family_id' => $user->family_id, 'is_expense' => true, 'is_income' => false]);

        $import->update(['status' => 'confirmed']);

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    ['amount' => 20.00, 'type' => 'expense', 'category_id' => $categoryA->id],
                    ['amount' => 22.50, 'type' => 'expense', 'category_id' => $categoryB->id],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_confirm_split_does_not_create_merchant_rule(): void
    {
        $user = $this->familyUser();
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-split-6');
        $categoryA = Category::factory()->create(['family_id' => $user->family_id, 'is_expense' => true, 'is_income' => false]);
        $categoryB = Category::factory()->create(['family_id' => $user->family_id, 'is_expense' => true, 'is_income' => false]);

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    ['amount' => 20.00, 'type' => 'expense', 'category_id' => $categoryA->id],
                    ['amount' => 22.50, 'type' => 'expense', 'category_id' => $categoryB->id],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('plaid_merchant_rules', ['user_id' => $user->id]);
    }

    public function test_confirm_split_applies_family_split_and_advance_fund_per_line(): void
    {
        $user = $this->familyUser();
        $spouse = User::factory()->create(['family_id' => $user->family_id]);
        ['import' => $import] = $this->createPendingImportForUser($user, 'txn-split-7');
        $category = Category::factory()->create([
            'family_id' => $user->family_id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'family_id' => null]);

        $this->actingAs($user)
            ->postJson("/plaid/pending-imports/{$import->id}/confirm-split", [
                'lines' => [
                    [
                        'amount' => 30.00,
                        'type' => 'expense',
                        'category_id' => $category->id,
                        'is_split' => true,
                        'split_data' => [
                            ['user_id' => $user->id, 'share_percentage' => 50],
                            ['user_id' => $spouse->id, 'share_percentage' => 50],
                        ],
                    ],
                    [
                        'amount' => 12.50,
                        'type' => 'expense',
                        'category_id' => $category->id,
                        'advance_fund_id' => $fund->id,
                    ],
                ],
            ])
            ->assertOk();

        $splitTx = Transaction::query()
            ->where('plaid_pending_import_id', $import->id)
            ->where('amount', 30.00)
            ->first();
        $this->assertNotNull($splitTx);
        $this->assertTrue($splitTx->is_split);
        $this->assertCount(2, $splitTx->splits);

        $advanceTx = Transaction::query()
            ->where('plaid_pending_import_id', $import->id)
            ->where('amount', 12.50)
            ->first();
        $this->assertNotNull($advanceTx);
        $this->assertSame($fund->id, $advanceTx->advance_fund_id);
        $this->assertSame($fund->id, $advanceTx->fund_id);
    }
}
