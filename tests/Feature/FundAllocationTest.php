<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_triggers_fund_rule_allocation(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        FundRule::factory()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => '10% allocation',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/transactions', [
            'amount' => 1000.00,
            'description' => 'Monthly salary',
            'type' => 'income',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
        ])->assertStatus(201);

        $fund->refresh();
        $this->assertEquals(100.00, $fund->balance);

        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'allocation',
            'amount' => 100.00,
        ]);
    }

    public function test_remaining_base_respects_prior_allocations(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fundA = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);
        $fundB = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        FundRule::factory()->create([
            'user_id' => $user->id,
            'fund_id' => $fundA->id,
            'name' => '10% gross',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'order' => 1,
            'is_active' => true,
        ]);

        FundRule::factory()->create([
            'user_id' => $user->id,
            'fund_id' => $fundB->id,
            'name' => '50% remaining',
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'order' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/transactions', [
            'amount' => 1000.00,
            'description' => 'Monthly salary',
            'type' => 'income',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
        ])->assertStatus(201);

        $fundA->refresh();
        $fundB->refresh();

        $this->assertEquals(100.00, $fundA->balance);
        $this->assertEquals(450.00, $fundB->balance);
    }

    public function test_borrow_from_fund_creates_debt_and_reduces_balance(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 500.00]);

        $this->actingAs($user)->postJson("/funds/{$fund->id}/borrow", [
            'amount' => 200.00,
            'description' => 'Emergency expense',
        ])->assertStatus(201);

        $fund->refresh();
        $this->assertEquals(300.00, $fund->balance);

        $this->assertDatabaseHas('debts', [
            'debtor_id' => $user->id,
            'fund_id' => $fund->id,
            'amount' => 200.00,
            'balance' => 200.00,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'income',
            'is_borrow' => true,
            'amount' => 200.00,
        ]);
    }

    public function test_borrow_fails_when_insufficient_balance(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $this->actingAs($user)->postJson("/funds/{$fund->id}/borrow", [
            'amount' => 200.00,
            'description' => 'Too much',
        ])->assertStatus(422);

        $fund->refresh();
        $this->assertEquals(100.00, $fund->balance);
    }
}
