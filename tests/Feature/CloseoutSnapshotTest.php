<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\MonthHardClose;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseoutSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_close_stores_settings_and_results_snapshots(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 2000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'Save 10',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 8,
        ])->assertOk();

        $hardClose = MonthHardClose::query()->first();
        $this->assertNotNull($hardClose);
        $this->assertSame('classic', $hardClose->closeout_mode);
        $this->assertIsArray($hardClose->settings_snapshot);
        $this->assertSame(1, $hardClose->settings_snapshot['version']);
        $this->assertSame('classic', $hardClose->settings_snapshot['closeout_mode']);
        $this->assertSame('Save 10', $hardClose->settings_snapshot['personal_rules'][(string) $user->id][0]['name']);
        $this->assertIsArray($hardClose->results_snapshot);
        $this->assertSame('classic', $hardClose->results_snapshot['mode']);
        $this->assertEqualsWithDelta(
            200.0,
            (float) $hardClose->results_snapshot['members'][(string) $user->id]['rules'][0]['projected_amount'],
            0.01
        );
    }

    public function test_month_summary_after_hard_close_keeps_snapshotted_rule_amounts_when_rules_change(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 2000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        $rule = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'Save 10',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 8,
        ])->assertOk();

        $rule->update(['amount' => 50]);

        $summary = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();

        $this->assertSame('snapshot', $summary->json('closeout_preview.source'));
        $this->assertEqualsWithDelta(200.0, (float) $summary->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $summary->json('rule_preview.rules.0.amount'), 0.01);
    }

    public function test_legacy_hard_close_without_snapshot_reconstructs_from_artifacts(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 125]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'closeout_allocation',
            'amount' => 125,
            'description' => 'Closeout rule: Old save (2026-08)',
        ]);

        MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 8,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'closeout_mode' => null,
            'settings_snapshot' => null,
            'results_snapshot' => null,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'New rule',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 90,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
        ]);

        $summary = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();

        $this->assertSame('reconstructed', $summary->json('closeout_preview.source'));
        $this->assertEqualsWithDelta(125.0, (float) $summary->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertNotEquals(900.0, (float) $summary->json('rule_preview.rules.0.projected_amount'));
    }

    public function test_switching_to_family_pooled_does_not_change_classic_hard_closed_month_summary(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $saveFund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);
        $advanceFund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 1000]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 2000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 500,
            'transaction_date' => '2026-08-04',
            'is_split' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 300,
            'transaction_date' => '2026-08-08',
            'is_split' => false,
            'advance_fund_id' => $advanceFund->id,
            'exclude_from_expense_basis' => true,
            'is_necessity' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $saveFund->id,
            'name' => 'Save 10 remaining',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $saveFund->id,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 8,
        ])->assertOk();

        $before = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertSame('snapshot', $before->json('closeout_preview.source'));
        $this->assertSame('classic', $before->json('closeout_preview.mode'));
        $this->assertEqualsWithDelta(1500.0, (float) $before->json('rule_preview.basis.remaining_after_expenses'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $before->json('rule_preview.basis.expense_basis_exclusions'), 0.01);
        $this->assertEqualsWithDelta(150.0, (float) $before->json('rule_preview.rules.0.projected_amount'), 0.01);

        $saveBalanceAfterClose = (float) $saveFund->fresh()->balance;
        $advanceBalanceAfterClose = (float) $advanceFund->fresh()->balance;

        $this->actingAs($user)
            ->putJson('/family/closeout-settings', ['closeout_mode' => 'family_pooled'])
            ->assertOk()
            ->assertJson(['closeout_mode' => 'family_pooled']);

        $after = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertSame('snapshot', $after->json('closeout_preview.source'));
        $this->assertSame('classic', $after->json('closeout_preview.mode'));
        $this->assertEqualsWithDelta(1500.0, (float) $after->json('rule_preview.basis.remaining_after_expenses'), 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $after->json('rule_preview.basis.expense_basis_exclusions'), 0.01);
        $this->assertEqualsWithDelta(150.0, (float) $after->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertEqualsWithDelta($saveBalanceAfterClose, (float) $saveFund->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($advanceBalanceAfterClose, (float) $advanceFund->fresh()->balance, 0.01);

        $this->actingAs($user)
            ->getJson('/closeout/closed-months')
            ->assertOk()
            ->assertJsonFragment([
                'year' => 2026,
                'month' => 8,
                'closeout_mode' => 'classic',
            ]);
    }

    public function test_switching_to_family_pooled_does_not_recompute_legacy_reconstructed_classic_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 125]);
        $advanceFund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 400]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 200,
            'transaction_date' => '2026-08-08',
            'is_split' => false,
            'advance_fund_id' => $advanceFund->id,
            'exclude_from_expense_basis' => true,
            'is_necessity' => false,
        ]);

        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'closeout_allocation',
            'amount' => 125,
            'description' => 'Closeout rule: Old save (2026-08)',
        ]);

        MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 8,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'closeout_mode' => null,
            'settings_snapshot' => null,
            'results_snapshot' => null,
        ]);

        $this->actingAs($user)
            ->putJson('/family/closeout-settings', ['closeout_mode' => 'family_pooled'])
            ->assertOk();

        $summary = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();

        $this->assertSame('reconstructed', $summary->json('closeout_preview.source'));
        $this->assertSame('classic', $summary->json('closeout_preview.mode'));
        $this->assertEqualsWithDelta(125.0, (float) $summary->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $summary->json('rule_preview.basis.expense_basis_exclusions'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $summary->json('rule_preview.basis.remaining_after_expenses'), 0.01);
    }

    public function test_switching_back_to_classic_does_not_change_family_pooled_hard_closed_month_summary(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 12, 12, 0, 0));

        $family = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $charityFund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => $family->id,
            'balance' => 0,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 200,
            'transaction_date' => '2026-08-04',
            'is_split' => false,
            'is_necessity' => true,
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

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 8,
        ])->assertOk();

        $before = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertSame('snapshot', $before->json('closeout_preview.source'));
        $this->assertSame('family_pooled', $before->json('closeout_preview.mode'));
        $this->assertEqualsWithDelta(80.0, (float) $before->json('closeout_preview.family.surplus_rules.0.projected_amount'), 0.01);

        $charityBalanceAfterClose = (float) $charityFund->fresh()->balance;

        $this->actingAs($user)
            ->putJson('/family/closeout-settings', ['closeout_mode' => 'classic'])
            ->assertOk()
            ->assertJson(['closeout_mode' => 'classic']);

        $after = $this->actingAs($user)->getJson('/month-summary?year=2026&month=8')->assertOk();
        $this->assertSame('snapshot', $after->json('closeout_preview.source'));
        $this->assertSame('family_pooled', $after->json('closeout_preview.mode'));
        $this->assertEqualsWithDelta(80.0, (float) $after->json('closeout_preview.family.surplus_rules.0.projected_amount'), 0.01);
        $this->assertEqualsWithDelta($charityBalanceAfterClose, (float) $charityFund->fresh()->balance, 0.01);
    }
}
