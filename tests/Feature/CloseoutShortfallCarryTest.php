<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\MonthHardClose;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseoutShortfallCarryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_family_overspend_allocates_nothing_and_carries_only_unfunded_spend(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 12, 0, 0));

        [$family, $head, $member, $charity, $advanceFund] = $this->familyWithCharityAndAdvanceFund();

        $this->income($family, $head, 5000, '2026-09-01');
        $this->expense($family, $head, 5700, '2026-09-05');
        $this->expense($family, $head, 300, '2026-09-08', $advanceFund->id);

        $this->hardClose($head, $member, 2026, 9);

        $this->assertEqualsWithDelta(0.0, (float) $charity->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(700.0, (float) $advanceFund->fresh()->balance, 0.01);

        $snapshot = MonthHardClose::query()->where('family_id', $family->id)->where('month', 9)->first();
        $this->assertEqualsWithDelta(700.0, (float) $snapshot->results_snapshot['shortfall']['amount'], 0.01);
        $this->assertEqualsWithDelta(6000.0, (float) $snapshot->results_snapshot['family']['basis']['necessary_expenses'], 0.01);
        $this->assertEqualsWithDelta(5700.0, (float) $snapshot->results_snapshot['family']['basis']['non_advance_necessary_expenses'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $snapshot->results_snapshot['family']['basis']['surplus_allocations_total'], 0.01);
        $this->assertEqualsWithDelta(700.0, (float) $snapshot->results_snapshot['family']['basis']['shortfall_carried_forward'], 0.01);

        $this->income($family, $head, 5000, '2026-10-01');

        $october = $this->actingAs($head)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $basis = $october->json('closeout_preview.family.basis');
        $this->assertEqualsWithDelta(700.0, (float) $basis['shortfall_brought_forward'], 0.01);
        $this->assertEqualsWithDelta(4300.0, (float) $basis['charity_base'], 0.01);
        $this->assertEqualsWithDelta(430.0, (float) $basis['surplus_allocations_total'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $basis['shortfall_carried_forward'], 0.01);
    }

    public function test_profitable_month_with_advance_nets_the_fund_and_carries_nothing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 1000,
        ]);

        $this->income($family, $user, 5000, '2026-09-01');
        $this->expense($family, $user, 300, '2026-09-08', $fund->id);

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
            'month' => 9,
        ])->assertOk();

        $this->assertEqualsWithDelta(1200.0, (float) $fund->fresh()->balance, 0.01);

        $closed = $this->actingAs($user)->getJson('/month-summary?year=2026&month=9')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $closed->json('rule_preview.basis.shortfall_carried_forward'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $closed->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $closed->json('rule_preview.basis.non_advance_expenses'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $closed->json('rule_preview.basis.gross_taken_from_pool'), 0.01);
        $this->assertEqualsWithDelta(4500.0, (float) $closed->json('rule_preview.basis.remaining_after_expenses'), 0.01);

        $october = $this->actingAs($user)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $october->json('rule_preview.basis.shortfall_brought_forward'), 0.01);
    }

    public function test_break_even_plus_advance_drops_the_fund_and_carries_nothing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 1000,
        ]);

        $this->income($family, $user, 5000, '2026-09-01');
        $this->expense($family, $user, 5000, '2026-09-05');
        $this->expense($family, $user, 300, '2026-09-08', $fund->id);

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
            'month' => 9,
        ])->assertOk();

        $this->assertEqualsWithDelta(700.0, (float) $fund->fresh()->balance, 0.01);

        $closed = $this->actingAs($user)->getJson('/month-summary?year=2026&month=9')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $closed->json('rule_preview.basis.shortfall_carried_forward'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $closed->json('rule_preview.rules.0.projected_amount'), 0.01);
        $this->assertEqualsWithDelta(-300.0, (float) $closed->json('rule_preview.rules.0.net_after_advances'), 0.01);
    }

    public function test_mode_switch_splits_or_sums_the_previous_shortfall(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 12, 0, 0));

        [$family, $head, $member] = $this->twoMemberFamily('family_pooled');
        $this->income($family, $head, 1000, '2026-09-01');
        $this->expense($family, $head, 1700, '2026-09-05');
        $this->hardClose($head, $member, 2026, 9);

        $family->update(['closeout_mode' => 'classic']);
        $head->unsetRelations();
        $member->unsetRelations();

        $headOctober = $this->actingAs($head)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $memberOctober = $this->actingAs($member)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertEqualsWithDelta(350.0, (float) $headOctober->json('rule_preview.basis.shortfall_brought_forward'), 0.01);
        $this->assertEqualsWithDelta(350.0, (float) $memberOctober->json('rule_preview.basis.shortfall_brought_forward'), 0.01);

        $classic = Family::factory()->create();
        $personA = User::factory()->create([
            'family_id' => $classic->id,
            'role' => 'head_of_household',
        ]);
        $personB = User::factory()->create([
            'family_id' => $classic->id,
            'role' => 'member',
        ]);
        $this->income($classic, $personA, 1000, '2026-09-01');
        $this->expense($classic, $personA, 1200, '2026-09-04');
        $this->income($classic, $personB, 1000, '2026-09-01');
        $this->expense($classic, $personB, 1300, '2026-09-04');
        $this->hardClose($personA, $personB, 2026, 9);

        $classic->update(['closeout_mode' => 'family_pooled']);
        $personA->unsetRelations();

        $pooled = $this->actingAs($personA)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertEqualsWithDelta(500.0, (float) $pooled->json('closeout_preview.family.basis.shortfall_brought_forward'), 0.01);
    }

    public function test_open_or_legacy_previous_month_does_not_carry_and_undo_clears_open_months_only(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 20, 12, 0, 0));

        [$family, $head, $member, $charity] = $this->familyWithCharityAndAdvanceFund();
        $this->income($family, $head, 1000, '2026-09-01');
        $this->expense($family, $head, 1800, '2026-09-05');

        $openOctober = $this->actingAs($head)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $openOctober->json('closeout_preview.family.basis.shortfall_brought_forward'), 0.01);

        $this->hardClose($head, $member, 2026, 9);

        $this->income($family, $head, 5000, '2026-10-01');
        $this->hardClose($head, $member, 2026, 10);

        $octoberBeforeUndo = $this->actingAs($head)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertEqualsWithDelta(800.0, (float) $octoberBeforeUndo->json('closeout_preview.family.basis.shortfall_brought_forward'), 0.01);
        $this->assertEqualsWithDelta(420.0, (float) $charity->fresh()->balance, 0.01);

        $this->actingAs($head)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 9,
        ])->assertOk();

        $octoberAfterUndo = $this->actingAs($head)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertSame('snapshot', $octoberAfterUndo->json('closeout_preview.source'));
        $this->assertEqualsWithDelta(800.0, (float) $octoberAfterUndo->json('closeout_preview.family.basis.shortfall_brought_forward'), 0.01);

        $november = $this->actingAs($head)->getJson('/month-summary?year=2026&month=11')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $november->json('closeout_preview.family.basis.shortfall_brought_forward'), 0.01);

        $legacyFamily = Family::factory()->create(['closeout_mode' => 'family_pooled']);
        $legacyUser = User::factory()->create([
            'family_id' => $legacyFamily->id,
            'role' => 'head_of_household',
        ]);
        MonthHardClose::query()->create([
            'family_id' => $legacyFamily->id,
            'year' => 2026,
            'month' => 9,
            'closed_at' => now(),
            'closed_by_user_id' => $legacyUser->id,
            'closeout_mode' => 'family_pooled',
            'settings_snapshot' => ['version' => 1],
            'results_snapshot' => ['mode' => 'family_pooled', 'family' => null, 'members' => []],
        ]);

        $legacyOctober = $this->actingAs($legacyUser)->getJson('/month-summary?year=2026&month=10')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $legacyOctober->json('closeout_preview.family.basis.shortfall_brought_forward'), 0.01);
    }

    /**
     * @return array{0: Family, 1: User, 2: User, 3: Fund, 4: Fund}
     */
    private function familyWithCharityAndAdvanceFund(): array
    {
        [$family, $head, $member] = $this->twoMemberFamily('family_pooled');
        $charity = Fund::factory()->create([
            'user_id' => $head->id,
            'family_id' => $family->id,
            'name' => 'Charity',
            'balance' => 0,
        ]);
        $advanceFund = Fund::factory()->create([
            'user_id' => $head->id,
            'balance' => 1000,
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
            'destination_id' => $charity->id,
        ]);

        return [$family, $head, $member, $charity, $advanceFund];
    }

    /**
     * @return array{0: Family, 1: User, 2: User}
     */
    private function twoMemberFamily(string $mode): array
    {
        $family = Family::factory()->create(['closeout_mode' => $mode]);
        $head = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        return [$family, $head, $member];
    }

    private function income(Family $family, User $user, float $amount, string $date): void
    {
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => $amount,
            'transaction_date' => $date,
            'is_split' => false,
        ]);
    }

    private function expense(Family $family, User $user, float $amount, string $date, ?int $advanceFundId = null): void
    {
        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => $amount,
            'transaction_date' => $date,
            'is_split' => false,
            'is_necessity' => true,
            'advance_fund_id' => $advanceFundId,
        ]);
    }

    private function hardClose(User $head, User $member, int $year, int $month): void
    {
        $this->actingAs($head)->postJson('/closeout/soft-close', [
            'year' => $year,
            'month' => $month,
        ])->assertOk();
        $this->actingAs($member)->postJson('/closeout/soft-close', [
            'year' => $year,
            'month' => $month,
        ])->assertOk();
        $this->actingAs($head)->postJson('/closeout/hard-close', [
            'year' => $year,
            'month' => $month,
        ])->assertOk();
    }
}
