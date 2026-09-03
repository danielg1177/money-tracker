<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_increase_fund_balance(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.00,
        ]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/override", [
            'balance' => 175.50,
        ]);

        $response->assertStatus(201);
        $fund->refresh();
        $this->assertEquals(175.50, (float) $fund->balance);
        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'manual_override',
            'amount' => '75.50',
            'description' => 'Set to $175.50',
        ]);
    }

    public function test_authenticated_user_can_decrease_fund_balance(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 400.00,
        ]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/override", [
            'balance' => 250.00,
            'description' => 'Reconciled to statement',
        ]);

        $response->assertStatus(201);
        $fund->refresh();
        $this->assertEquals(250.00, (float) $fund->balance);
        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'manual_override',
            'amount' => '-150.00',
            'description' => 'Set to $250.00 — Reconciled to statement',
        ]);
    }

    public function test_override_can_set_balance_to_zero(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 40.00,
        ]);

        $this->actingAs($user)->postJson("/funds/{$fund->id}/override", [
            'balance' => 0,
        ])->assertStatus(201);

        $fund->refresh();
        $this->assertEquals(0.00, (float) $fund->balance);
        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'type' => 'manual_override',
            'amount' => '-40.00',
        ]);
    }

    public function test_override_does_not_create_a_transaction(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.00,
        ]);

        $transactionCountBefore = Transaction::query()->count();

        $this->actingAs($user)->postJson("/funds/{$fund->id}/override", [
            'balance' => 80.00,
        ])->assertStatus(201);

        $this->assertEquals($transactionCountBefore, Transaction::query()->count());
    }

    public function test_override_same_balance_is_rejected(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.00,
        ]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/override", [
            'balance' => 100.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'New balance must differ from the current fund balance.']);
        $this->assertDatabaseCount('fund_movements', 0);
    }

    public function test_override_balance_is_required(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/override", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['balance']);
    }

    public function test_unauthenticated_user_cannot_override(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $response = $this->postJson("/funds/{$fund->id}/override", ['balance' => 50.00]);

        $response->assertStatus(401);
    }

    public function test_family_member_can_override_a_family_fund(): void
    {
        $family = Family::factory()->create();
        $owner = User::factory()->create(['family_id' => $family->id]);
        $member = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create([
            'user_id' => $owner->id,
            'family_id' => $family->id,
            'balance' => 80.00,
        ]);

        $this->actingAs($member)->postJson("/funds/{$fund->id}/override", [
            'balance' => 90.00,
        ])->assertStatus(201);

        $fund->refresh();
        $this->assertEquals(90.00, (float) $fund->balance);
    }

    public function test_user_cannot_override_another_users_personal_fund(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $owner->id, 'balance' => 100.00]);

        $response = $this->actingAs($other)->postJson("/funds/{$fund->id}/override", [
            'balance' => 50.00,
        ]);

        $response->assertStatus(403);
    }

    public function test_month_summary_fund_in_out_excludes_manual_override(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
            'balance' => 200.00,
        ]);

        $this->actingAs($user)->postJson("/funds/{$fund->id}/override", [
            'balance' => 50.00,
        ])->assertStatus(201);

        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'type' => 'manual_override',
        ]);

        $year = now()->year;
        $month = now()->month;
        $summary = $this->actingAs($user)->getJson("/month-summary?year={$year}&month={$month}")->assertOk();

        $this->assertEqualsWithDelta(0.00, (float) data_get($summary->json(), 'fund_movements.totals.in'), 0.01);
        $this->assertEqualsWithDelta(0.00, (float) data_get($summary->json(), 'fund_movements.totals.out'), 0.01);
        $this->assertCount(0, data_get($summary->json(), 'fund_movements.by_fund', []));
        $this->assertEquals(1, FundMovement::query()->where('type', 'manual_override')->count());
    }
}
