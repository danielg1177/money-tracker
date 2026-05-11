<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidCalibrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaidCalibrationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plaidHttpConfig(): void
    {
        config([
            'plaid.client_id' => 'test_client',
            'plaid.secret' => 'test_secret',
            'plaid.base_url' => 'https://sandbox.plaid.com',
            'plaid.api_version' => '2020-09-14',
        ]);
    }

    public function test_build_calibration_matches_pairs_plaid_with_ledger_in_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00'));

        $this->plaidHttpConfig();

        Http::fake([
            'https://sandbox.plaid.com/transactions/get' => Http::response([
                'transactions' => [
                    [
                        'transaction_id' => 'pl-tx-1',
                        'account_id' => 'acc',
                        'amount' => 25.5,
                        'date' => '2026-03-15',
                        'name' => 'Test Merchant',
                        'merchant_name' => 'Test Merchant',
                    ],
                ],
                'total_transactions' => 1,
            ], 200),
        ]);

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
            'amount' => 25.5,
            'description' => 'Test Merchant lunch',
            'transaction_date' => '2026-03-15',
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-cal',
            'access_token' => 'tok',
        ]);

        $result = app(PlaidCalibrationService::class)->buildCalibrationMatches($item);

        $this->assertCount(1, $result['matched']);
        $this->assertSame(1.0, $result['matched'][0]['score']);
        $this->assertSame('pl-tx-1', $result['matched'][0]['plaid']['transaction_id']);
        $this->assertCount(0, $result['unmatched_plaid']);
        $this->assertCount(0, $result['unmatched_ledger']);
    }

    public function test_build_calibration_puts_extra_ledger_rows_in_unmatched_ledger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11'));

        $this->plaidHttpConfig();

        Http::fake([
            'https://sandbox.plaid.com/transactions/get' => Http::response([
                'transactions' => [],
                'total_transactions' => 0,
            ], 200),
        ]);

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $orphan = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 99,
            'transaction_date' => '2026-03-10',
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-cal-2',
            'access_token' => 'tok2',
        ]);

        $result = app(PlaidCalibrationService::class)->buildCalibrationMatches($item);

        $this->assertCount(0, $result['matched']);
        $this->assertCount(0, $result['unmatched_plaid']);
        $this->assertCount(1, $result['unmatched_ledger']);
        $this->assertTrue($result['unmatched_ledger'][0]->is($orphan));
    }

    public function test_build_calibration_without_family_lists_all_plaid_unmatched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-11'));

        $this->plaidHttpConfig();

        Http::fake([
            'https://sandbox.plaid.com/transactions/get' => Http::response([
                'transactions' => [
                    [
                        'transaction_id' => 'solo',
                        'amount' => 1,
                        'date' => '2026-03-01',
                        'name' => 'X',
                    ],
                ],
                'total_transactions' => 1,
            ], 200),
        ]);

        $user = User::factory()->create(['family_id' => null]);
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-nofam',
            'access_token' => 'tok3',
        ]);

        $result = app(PlaidCalibrationService::class)->buildCalibrationMatches($item);

        $this->assertCount(0, $result['matched']);
        $this->assertCount(1, $result['unmatched_plaid']);
        $this->assertSame('solo', $result['unmatched_plaid'][0]['plaid']['transaction_id']);
        $this->assertCount(0, $result['unmatched_ledger']);
    }

    public function test_apply_calibration_confirmed_pair_links_ledger_and_import_as_new_creates_pending(): void
    {
        $this->plaidHttpConfig();

        Http::fake([
            'https://sandbox.plaid.com/transactions/get' => Http::response([
                'transactions' => [],
                'total_transactions' => 0,
            ], 200),
        ]);

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $ledger = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 10,
            'description' => 'Coffee',
            'transaction_date' => '2026-03-05',
            'is_split' => false,
            'is_non_necessity' => false,
        ]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-apply',
            'access_token' => 'tok4',
        ]);

        $plaidConfirm = [
            'transaction_id' => 'plaid-link-1',
            'merchant_name' => 'Corner Store',
            'name' => 'CORNER',
            'amount' => 10,
            'date' => '2026-03-05',
        ];

        $plaidNew = [
            'transaction_id' => 'plaid-new-1',
            'account_id' => 'a1',
            'amount' => 5,
            'date' => '2026-03-20',
            'name' => 'OTHER',
        ];

        app(PlaidCalibrationService::class)->applyCalibrationResults($item, [
            ['plaid' => $plaidConfirm, 'ledger' => $ledger],
        ], [$plaidNew]);

        $ledger->refresh();
        $this->assertSame('plaid-link-1', $ledger->plaid_transaction_id);
        $this->assertSame('plaid', $ledger->import_source);

        $this->assertDatabaseHas('plaid_merchant_rules', [
            'user_id' => $user->id,
            'merchant_key' => PlaidMerchantRule::normalizeKey('Corner Store'),
        ]);

        $this->assertDatabaseHas('plaid_pending_imports', [
            'plaid_transaction_id' => 'plaid-new-1',
            'status' => 'pending',
        ]);
    }

    public function test_apply_calibration_skips_import_when_pending_already_exists(): void
    {
        $this->plaidHttpConfig();

        Http::fake([
            'https://sandbox.plaid.com/transactions/get' => Http::response([
                'transactions' => [],
                'total_transactions' => 0,
            ], 200),
        ]);

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-dup',
            'access_token' => 'tok5',
        ]);

        PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'dup-tx',
            'amount' => 1,
            'date' => '2026-03-01',
            'raw_name' => 'x',
            'suggested_type' => 'expense',
            'suggested_is_non_necessity' => false,
            'confidence_score' => 0,
            'status' => 'pending',
            'raw_payload' => [],
        ]);

        app(PlaidCalibrationService::class)->applyCalibrationResults($item, [], [
            [
                'transaction_id' => 'dup-tx',
                'amount' => 1,
                'date' => '2026-03-01',
                'name' => 'x',
            ],
        ]);

        $this->assertSame(1, PlaidPendingImport::query()->where('plaid_transaction_id', 'dup-tx')->count());
    }
}
