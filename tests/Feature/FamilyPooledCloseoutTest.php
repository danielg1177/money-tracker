<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyPooledCloseoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_pooled_pipeline_charity_remaining_and_inverse_split(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 12, 0, 0));

        $family = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $personA = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'name' => 'PersonA',
        ]);
        $personB = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
            'name' => 'PersonB',
        ]);

        $charityFund = Fund::factory()->create([
            'user_id' => $personA->id,
            'family_id' => $family->id,
            'name' => 'Charity',
            'balance' => 0,
        ]);
        $xFund = Fund::factory()->create([
            'user_id' => $personA->id,
            'family_id' => $family->id,
            'name' => 'X Fund',
            'balance' => 0,
        ]);
        $yFund = Fund::factory()->create([
            'user_id' => $personA->id,
            'family_id' => $family->id,
            'name' => 'Y Fund',
            'balance' => 0,
        ]);
        $aPersonal = Fund::factory()->create([
            'user_id' => $personA->id,
            'family_id' => null,
            'name' => 'A leftover',
            'balance' => 0,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'income',
            'amount' => 10000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personB->id,
            'type' => 'income',
            'amount' => 5000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        $split = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'expense',
            'amount' => 10000,
            'transaction_date' => '2026-08-05',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $personA->id, 'share_percentage' => 70],
                ['user_id' => $personB->id, 'share_percentage' => 30],
            ],
        ]);
        TransactionSplit::query()->create([
            'transaction_id' => $split->id,
            'user_id' => $personA->id,
            'share_percentage' => 70,
            'amount' => 7000,
        ]);
        TransactionSplit::query()->create([
            'transaction_id' => $split->id,
            'user_id' => $personB->id,
            'share_percentage' => 30,
            'amount' => 3000,
        ]);

        $advanceFund = Fund::factory()->create([
            'user_id' => $personA->id,
            'family_id' => null,
            'balance' => 1000,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'expense',
            'amount' => 700,
            'transaction_date' => '2026-08-08',
            'is_split' => false,
            'advance_fund_id' => $advanceFund->id,
            'exclude_from_expense_basis' => true,
            'is_necessity' => false,
        ]);

        FamilyCloseoutRule::query()->create([
            'family_id' => $family->id,
            'name' => 'Charity 10',
            'order' => 1,
            'is_active' => true,
            'stage' => 'surplus',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => $charityFund->id,
        ]);
        FamilyCloseoutRule::query()->create([
            'family_id' => $family->id,
            'name' => 'X 20',
            'order' => 2,
            'is_active' => true,
            'stage' => 'remaining_after_charity',
            'allocation_type' => 'percentage',
            'amount' => 20,
            'destination_type' => 'fund',
            'destination_id' => $xFund->id,
        ]);
        FamilyCloseoutRule::query()->create([
            'family_id' => $family->id,
            'name' => 'Y 30',
            'order' => 3,
            'is_active' => true,
            'stage' => 'remaining_after_charity',
            'allocation_type' => 'percentage',
            'amount' => 30,
            'destination_type' => 'fund',
            'destination_id' => $yFund->id,
        ]);

        FundRule::query()->create([
            'user_id' => $personA->id,
            'fund_id' => $aPersonal->id,
            'name' => 'A leftover 50',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $aPersonal->id,
        ]);
        FundRule::query()->create([
            'user_id' => $personA->id,
            'fund_id' => $aPersonal->id,
            'name' => 'A gross skipped',
            'order' => 2,
            'allocation_type' => 'percentage',
            'amount' => 25,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $aPersonal->id,
        ]);

        $preview = $this->actingAs($personA)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertSame('family_pooled', $preview->json('closeout_preview.mode'));
        $this->assertSame('live', $preview->json('closeout_preview.source'));

        $basis = $preview->json('closeout_preview.family.basis');
        $this->assertEqualsWithDelta(15000.0, (float) $basis['earned_income'], 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $basis['necessary_expenses'], 0.01);
        $this->assertEqualsWithDelta(10700.0, (float) $basis['all_expenses'], 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $basis['charity_base'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $basis['surplus_allocations_total'], 0.01);
        $this->assertEqualsWithDelta(3800.0, (float) $basis['remaining_after_charity'], 0.01);
        $this->assertEqualsWithDelta(1900.0, (float) $basis['leftover'], 0.01);

        $members = collect($preview->json('closeout_preview.family.leftover_split.members'));
        $aSplit = $members->firstWhere('user_id', $personA->id);
        $bSplit = $members->firstWhere('user_id', $personB->id);
        $this->assertEqualsWithDelta(0.4286, (float) $aSplit['share'], 0.0001);
        $this->assertEqualsWithDelta(0.5714, (float) $bSplit['share'], 0.0001);
        $this->assertEqualsWithDelta(814.29, (float) $aSplit['member_pool'], 0.01);
        $this->assertEqualsWithDelta(1085.71, (float) $bSplit['member_pool'], 0.01);

        $this->assertEqualsWithDelta(7700.0, (float) $preview->json('rule_preview.basis.total_expenses'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $preview->json('rule_preview.basis.expense_basis_exclusions'), 0.01);
        $this->assertEqualsWithDelta(814.29, (float) $preview->json('rule_preview.basis.remaining_after_expenses'), 0.01);
        $this->assertNotEqualsWithDelta(
            (float) $preview->json('rule_preview.basis.gross_income') - (float) $preview->json('rule_preview.basis.total_expenses'),
            (float) $preview->json('rule_preview.basis.remaining_after_expenses'),
            1.0,
        );

        $this->assertCount(1, $preview->json('rule_preview.rules'));
        $this->assertSame('A leftover 50', $preview->json('rule_preview.rules.0.rule_name'));
        $this->assertEqualsWithDelta(407.15, (float) $preview->json('rule_preview.rules.0.projected_amount'), 0.01);

        $this->actingAs($personA)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 8])->assertOk();
        $this->actingAs($personB)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 8])->assertOk();
        $this->actingAs($personA)->postJson('/closeout/hard-close', ['year' => 2026, 'month' => 8])->assertOk();

        $this->assertEqualsWithDelta(500.0, (float) $charityFund->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(760.0, (float) $xFund->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(1140.0, (float) $yFund->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(407.15, (float) $aPersonal->fresh()->balance, 0.01);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $personA->id,
            'is_closeout_initiated' => true,
            'closeout_scope' => 'family',
            'amount' => 500,
        ]);
    }

    public function test_inter_member_debt_payments_are_excluded_from_family_closeout_totals(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 12, 0, 0));

        $family = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $personA = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $personB = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $personA->id,
            'creditor_id' => $personB->id,
            'amount' => 100,
            'balance' => 100,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'expense',
            'amount' => 100,
            'transaction_date' => '2026-08-04',
            'is_split' => false,
            'is_debt_payment' => true,
            'debt_id' => $debt->id,
        ]);

        $preview = $this->actingAs($personA)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $preview->json('closeout_preview.family.basis.necessary_expenses'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $preview->json('closeout_preview.family.basis.all_expenses'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $preview->json('closeout_preview.family.basis.charity_base'), 0.01);
    }

    public function test_family_pooled_charity_uses_is_necessity_not_expense_basis_exclusion(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 12, 0, 0));

        $family = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $personA = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'expense',
            'amount' => 200,
            'transaction_date' => '2026-08-04',
            'is_split' => false,
            'is_necessity' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'expense',
            'amount' => 100,
            'transaction_date' => '2026-08-05',
            'is_split' => false,
            'is_necessity' => true,
        ]);

        $preview = $this->actingAs($personA)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertEqualsWithDelta(100.0, (float) $preview->json('closeout_preview.family.basis.necessary_expenses'), 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $preview->json('closeout_preview.family.basis.non_necessity_expenses'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $preview->json('closeout_preview.family.basis.all_expenses'), 0.01);
        $this->assertEqualsWithDelta(900.0, (float) $preview->json('closeout_preview.family.basis.charity_base'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $preview->json('rule_preview.basis.total_expenses'), 0.01);
    }

    public function test_zero_income_member_gets_zero_leftover_weight(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 12, 0, 0));

        $family = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $personA = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $personB = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $personA->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        $preview = $this->actingAs($personA)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $members = collect($preview->json('closeout_preview.family.leftover_split.members'));
        $bSplit = $members->firstWhere('user_id', $personB->id);
        $this->assertEqualsWithDelta(0.0, (float) $bSplit['weight'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $bSplit['member_pool'], 0.01);
    }

    public function test_creating_expense_with_exclude_from_expense_basis_stores_false_when_family_pooled(): void
    {
        $family = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => false,
            'is_expense' => true,
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
        ]);
        FundRule::factory()->create([
            'user_id' => $user->id,
            'allocation_type' => 'percentage',
            'allocation_base' => 'remaining',
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'is_active' => true,
            'amount' => 10,
            'order' => 1,
        ]);

        $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 50,
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
            'advance_fund_id' => $fund->id,
            'exclude_from_expense_basis' => true,
        ])->assertCreated()
            ->assertJsonPath('exclude_from_expense_basis', false);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'advance_fund_id' => $fund->id,
            'exclude_from_expense_basis' => false,
        ]);
    }

    public function test_updating_expense_while_family_pooled_preserves_existing_exclude_from_expense_basis(): void
    {
        $family = Family::factory()->create(['closeout_mode' => 'classic']);
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => false,
            'is_expense' => true,
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
        ]);
        FundRule::factory()->create([
            'user_id' => $user->id,
            'allocation_type' => 'percentage',
            'allocation_base' => 'remaining',
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'is_active' => true,
            'amount' => 10,
            'order' => 1,
        ]);

        $created = $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 50,
            'description' => 'Advance',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
            'advance_fund_id' => $fund->id,
            'exclude_from_expense_basis' => true,
        ])->assertCreated()
            ->assertJsonPath('exclude_from_expense_basis', true);

        $family->update(['closeout_mode' => 'family_pooled']);
        $user->unsetRelation('family');

        $this->actingAs($user)->putJson('/transactions/'.$created->json('id'), [
            'category_id' => $category->id,
            'amount' => 50,
            'description' => 'Advance edited',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
            'advance_fund_id' => $fund->id,
            'exclude_from_expense_basis' => false,
        ])->assertOk()
            ->assertJsonPath('exclude_from_expense_basis', true);

        $this->assertTrue((bool) Transaction::query()->find($created->json('id'))?->exclude_from_expense_basis);
    }
}
