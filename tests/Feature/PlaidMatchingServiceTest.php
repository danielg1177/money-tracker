<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\PlaidMerchantRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlaidMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaidMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PlaidMatchingService
    {
        return app(PlaidMatchingService::class);
    }

    public function test_find_ledger_match_returns_null_when_no_matching_transaction(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $plaidRow = [
            'amount' => 99.99,
            'date' => '2026-05-10',
            'merchant_name' => 'Nobody',
        ];

        $this->assertNull($this->service()->findLedgerMatch($plaidRow, $family->id));
    }

    public function test_find_ledger_match_matches_expense_when_amount_date_and_merchant_align(): void
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
            'amount' => 25.5,
            'description' => 'Test Merchant coffee',
            'transaction_date' => '2026-05-02',
        ]);

        $plaidRow = [
            'amount' => 25.5,
            'date' => '2026-05-02',
            'merchant_name' => 'Test Merchant',
        ];

        $match = $this->service()->findLedgerMatch($plaidRow, $family->id);

        $this->assertNotNull($match);
        $this->assertTrue($match->is($transaction));
    }

    public function test_find_ledger_match_skips_rows_with_plaid_transaction_id(): void
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
            'description' => 'Already linked Store',
            'transaction_date' => '2026-05-02',
            'plaid_transaction_id' => 'plaid-existing',
        ]);

        $plaidRow = [
            'amount' => 10,
            'date' => '2026-05-02',
            'merchant_name' => 'Store',
        ];

        $this->assertNull($this->service()->findLedgerMatch($plaidRow, $family->id));
    }

    public function test_find_ledger_match_prefers_higher_merchant_similarity(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $weak = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 40,
            'description' => 'unrelated noise text',
            'transaction_date' => '2026-06-01',
        ]);

        $strong = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 40,
            'description' => 'Acme Groceries weekly',
            'transaction_date' => '2026-06-01',
        ]);

        $plaidRow = [
            'amount' => 40,
            'date' => '2026-06-01',
            'merchant_name' => 'Acme Groceries',
        ];

        $match = $this->service()->findLedgerMatch($plaidRow, $family->id);

        $this->assertNotNull($match);
        $this->assertTrue($match->is($strong));
        $this->assertFalse($match->is($weak));
    }

    public function test_find_ledger_match_returns_null_when_best_score_below_threshold(): void
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
            'amount' => 5,
            'description' => 'qqqqqqqq',
            'transaction_date' => '2026-04-01',
        ]);

        $plaidRow = [
            'amount' => 5,
            'date' => '2026-04-01',
            'merchant_name' => 'zzzzzzzz',
        ];

        $this->assertNull($this->service()->findLedgerMatch($plaidRow, $family->id));
    }

    public function test_find_ledger_match_income_negative_plaid_amount(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => true,
            'is_expense' => false,
        ]);

        $transaction = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount' => 100,
            'description' => 'Payroll deposit ACME CORP',
            'transaction_date' => '2026-03-15',
        ]);

        $plaidRow = [
            'amount' => -100,
            'date' => '2026-03-15',
            'name' => 'ACME CORP',
        ];

        $match = $this->service()->findLedgerMatch($plaidRow, $family->id);

        $this->assertNotNull($match);
        $this->assertTrue($match->is($transaction));
    }

    public function test_normalize_merchant_key_delegates_to_plaid_merchant_rule(): void
    {
        $this->assertSame(
            PlaidMerchantRule::normalizeKey('  Foo-Bar  99  '),
            $this->service()->normalizeMerchantKey('  Foo-Bar  99  ')
        );
    }

    public function test_get_suggestion_without_rule_uses_plaid_amount_sign_for_type(): void
    {
        $suggestion = $this->service()->getSuggestion([
            'amount' => -12.5,
            'merchant_name' => 'Unknown',
        ], 1);

        $this->assertSame('income', $suggestion['type']);
        $this->assertNull($suggestion['category_id']);
        $this->assertNull($suggestion['fund_id']);
        $this->assertNull($suggestion['advance_fund_id']);
        $this->assertFalse($suggestion['is_non_necessity']);
        $this->assertSame(0.0, $suggestion['confidence_score']);
        $this->assertFalse($suggestion['is_auto_eligible']);
    }

    public function test_get_suggestion_with_rule_returns_mapped_fields(): void
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
            'merchant_key' => PlaidMerchantRule::normalizeKey('Corner Cafe'),
            'category_id' => $category->id,
            'type' => 'expense',
            'fund_id' => null,
            'advance_fund_id' => null,
            'is_non_necessity' => true,
            'is_split' => false,
            'confirmation_count' => 4,
            'total_seen_count' => 4,
        ]);

        $suggestion = $this->service()->getSuggestion([
            'amount' => 3.5,
            'merchant_name' => 'Corner Cafe',
        ], $user->id);

        $this->assertSame($category->id, $suggestion['category_id']);
        $this->assertSame('expense', $suggestion['type']);
        $this->assertTrue($suggestion['is_non_necessity']);
        $this->assertSame(1.0, $suggestion['confidence_score']);
        $this->assertTrue($suggestion['is_auto_eligible']);
    }

    public function test_record_confirmation_increments_both_counters(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $rule = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'coffee shop',
            'category_id' => null,
            'type' => 'expense',
            'fund_id' => null,
            'advance_fund_id' => null,
            'is_non_necessity' => false,
            'is_split' => false,
            'confirmation_count' => 1,
            'total_seen_count' => 2,
        ]);

        $this->service()->recordConfirmation($rule);

        $rule->refresh();
        $this->assertSame(2, $rule->confirmation_count);
        $this->assertSame(3, $rule->total_seen_count);
    }

    public function test_record_seen_increments_total_seen_only(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $rule = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'gas station',
            'category_id' => null,
            'type' => 'expense',
            'fund_id' => null,
            'advance_fund_id' => null,
            'is_non_necessity' => false,
            'is_split' => false,
            'confirmation_count' => 1,
            'total_seen_count' => 2,
        ]);

        $this->service()->recordSeen($rule);

        $rule->refresh();
        $this->assertSame(1, $rule->confirmation_count);
        $this->assertSame(3, $rule->total_seen_count);
    }

    public function test_learn_from_confirmation_upserts_and_increments_counters(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $rule = $this->service()->learnFromConfirmation($user->id, 'Fresh Market!', [
            'category_id' => $category->id,
            'type' => 'expense',
            'is_non_necessity' => true,
        ]);

        $this->assertSame(PlaidMerchantRule::normalizeKey('Fresh Market!'), $rule->merchant_key);
        $this->assertSame($category->id, $rule->category_id);
        $this->assertTrue($rule->is_non_necessity);
        $this->assertSame(1, $rule->confirmation_count);
        $this->assertSame(1, $rule->total_seen_count);

        $this->service()->learnFromConfirmation($user->id, 'Fresh Market!', [
            'category_id' => $category->id,
            'type' => 'expense',
        ]);

        $rule->refresh();
        $this->assertSame(2, $rule->confirmation_count);
        $this->assertSame(2, $rule->total_seen_count);
    }
}
