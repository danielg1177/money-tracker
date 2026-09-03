<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Fund;
use App\Models\MonthHardClose;
use App\Models\PlaidItem;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaidMerchantRuleCategorySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_category_copies_family_necessity_onto_all_members_merchant_rules(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $other = User::factory()->create(['family_id' => $family->id]);
        $otherFund = Fund::factory()->create(['user_id' => $other->id, 'family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
            'is_necessity_default' => false,
        ]);

        $rule = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'corner cafe',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_necessity' => false,
            'exclude_from_expense_basis' => true,
            'confirmation_count' => 4,
            'total_seen_count' => 5,
            'action' => 'categorize',
        ]);
        $otherRule = PlaidMerchantRule::query()->create([
            'user_id' => $other->id,
            'merchant_key' => 'corner cafe',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_necessity' => false,
            'advance_fund_id' => $otherFund->id,
            'exclude_from_expense_basis' => true,
            'confirmation_count' => 3,
            'total_seen_count' => 3,
            'action' => 'categorize',
        ]);

        $this->actingAs($user)->putJson("/categories/{$category->id}", [
            'name' => $category->name,
            'is_income' => false,
            'is_expense' => true,
            'is_necessity_default' => true,
        ])->assertOk();

        $rule->refresh();
        $otherRule->refresh();

        $this->assertTrue($rule->is_necessity);
        $this->assertFalse($rule->exclude_from_expense_basis);
        $this->assertNull($rule->advance_fund_id);
        $this->assertSame(4, $rule->confirmation_count);
        $this->assertSame(5, $rule->total_seen_count);
        $this->assertTrue($otherRule->is_necessity);
        $this->assertSame($otherFund->id, (int) $otherRule->advance_fund_id);
        $this->assertTrue($otherRule->exclude_from_expense_basis);
    }

    public function test_apply_all_updates_pending_suggestions_and_open_auto_created_rows(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
            'is_necessity_default' => true,
        ]);

        PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'fresh market',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_necessity' => false,
            'exclude_from_expense_basis' => true,
            'confirmation_count' => 3,
            'total_seen_count' => 3,
            'action' => 'categorize',
        ]);

        $item = $this->createPlaidItem($user);
        $pending = PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-pending-sync',
            'amount' => 12.5,
            'date' => now()->toDateString(),
            'merchant_name' => 'Fresh Market',
            'raw_name' => 'FRESH MARKET',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_is_necessity' => false,
            'suggested_exclude_from_expense_basis' => true,
            'confidence_score' => 0.9,
            'status' => 'pending',
            'raw_payload' => [],
        ]);

        $transaction = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 20,
            'transaction_date' => now()->toDateString(),
            'is_necessity' => false,
            'exclude_from_expense_basis' => true,
        ]);
        PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-auto-sync',
            'amount' => 20,
            'date' => now()->toDateString(),
            'merchant_name' => 'Fresh Market',
            'raw_name' => 'FRESH MARKET',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_is_necessity' => false,
            'confidence_score' => 1,
            'status' => 'auto_created',
            'transaction_id' => $transaction->id,
            'raw_payload' => [],
        ]);

        $this->actingAs($user)
            ->postJson('/categories/sync-plaid-rules')
            ->assertOk()
            ->assertJsonPath('merchant_rules', 1)
            ->assertJsonPath('pending_imports', 2)
            ->assertJsonPath('auto_created_transactions', 1);

        $pending->refresh();
        $transaction->refresh();

        $this->assertTrue($pending->suggested_is_necessity);
        $this->assertFalse($pending->suggested_exclude_from_expense_basis);
        $this->assertTrue($transaction->is_necessity);
        $this->assertFalse($transaction->exclude_from_expense_basis);
    }

    public function test_closed_month_auto_created_transaction_is_left_alone(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
            'is_necessity_default' => true,
        ]);

        $closedDate = now()->startOfMonth()->subMonth()->toDateString();
        MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => (int) now()->startOfMonth()->subMonth()->format('Y'),
            'month' => (int) now()->startOfMonth()->subMonth()->format('n'),
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
        ]);

        $transaction = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 20,
            'transaction_date' => $closedDate,
            'is_necessity' => false,
        ]);
        $item = $this->createPlaidItem($user);
        PlaidPendingImport::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'plaid_transaction_id' => 'txn-closed-auto',
            'amount' => 20,
            'date' => $closedDate,
            'merchant_name' => 'Old Store',
            'raw_name' => 'OLD STORE',
            'suggested_category_id' => $category->id,
            'suggested_type' => 'expense',
            'suggested_is_necessity' => false,
            'confidence_score' => 1,
            'status' => 'auto_created',
            'transaction_id' => $transaction->id,
            'raw_payload' => [],
        ]);

        $this->actingAs($user)
            ->postJson('/categories/sync-plaid-rules')
            ->assertOk()
            ->assertJsonPath('auto_created_transactions', 0);

        $this->assertFalse($transaction->fresh()->is_necessity);
    }

    public function test_user_without_family_cannot_sync_plaid_rules(): void
    {
        $user = User::factory()->create(['family_id' => null]);

        $this->actingAs($user)
            ->postJson('/categories/sync-plaid-rules')
            ->assertForbidden();
    }

    public function test_artisan_command_syncs_all_family_users(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
            'is_necessity_default' => true,
        ]);
        $rule = PlaidMerchantRule::query()->create([
            'user_id' => $user->id,
            'merchant_key' => 'command cafe',
            'category_id' => $category->id,
            'type' => 'expense',
            'is_necessity' => false,
            'confirmation_count' => 3,
            'total_seen_count' => 3,
            'action' => 'categorize',
        ]);

        $this->artisan('plaid:sync-merchant-rules-from-categories')
            ->expectsOutput('Updated 1 merchant rules, 0 pending imports, 0 auto-created transactions')
            ->assertSuccessful();

        $this->assertTrue($rule->fresh()->is_necessity);
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
}
