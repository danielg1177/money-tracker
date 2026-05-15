<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_sweep_full_balance(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 400.00,
        ]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/sweep", [
            'amount' => 400.00,
        ]);

        $response->assertStatus(201);
        $fund->refresh();
        $this->assertEquals(0.00, (float) $fund->balance);
        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'savings_sweep',
            'amount' => '400.00',
        ]);
    }

    public function test_authenticated_user_can_sweep_partial_balance(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 400.00,
        ]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/sweep", [
            'amount' => 300.00,
            'description' => 'March savings deposit',
        ]);

        $response->assertStatus(201);
        $fund->refresh();
        $this->assertEquals(100.00, (float) $fund->balance);
        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'savings_sweep',
            'amount' => '300.00',
            'description' => 'March savings deposit',
        ]);
    }

    public function test_sweep_does_not_create_a_transaction(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 400.00,
        ]);

        $transactionCountBefore = Transaction::query()->count();

        $this->actingAs($user)->postJson("/funds/{$fund->id}/sweep", [
            'amount' => 400.00,
        ])->assertStatus(201);

        $this->assertEquals($transactionCountBefore, Transaction::query()->count());
    }

    public function test_sweep_amount_cannot_exceed_fund_balance(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 100.00,
        ]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/sweep", [
            'amount' => 200.00,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Sweep amount cannot exceed the current fund balance.']);
        $fund->refresh();
        $this->assertEquals(100.00, (float) $fund->balance);
        $this->assertDatabaseCount('fund_movements', 0);
    }

    public function test_sweep_amount_is_required(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/sweep", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_sweep_amount_must_be_positive(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $response = $this->actingAs($user)->postJson("/funds/{$fund->id}/sweep", [
            'amount' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);
    }

    public function test_unauthenticated_user_cannot_sweep(): void
    {
        $user = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $user->id, 'balance' => 100.00]);

        $response = $this->postJson("/funds/{$fund->id}/sweep", ['amount' => 50.00]);

        $response->assertStatus(401);
    }

    public function test_user_cannot_sweep_another_users_fund(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $fund = Fund::factory()->create(['user_id' => $owner->id, 'balance' => 100.00]);

        $response = $this->actingAs($other)->postJson("/funds/{$fund->id}/sweep", [
            'amount' => 50.00,
        ]);

        $response->assertStatus(403);
    }
}
