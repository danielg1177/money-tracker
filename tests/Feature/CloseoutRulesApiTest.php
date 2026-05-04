<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseoutRulesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_closeout_rule(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id]);

        $rule = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => null,
            'name' => 'My rule',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'destination_title' => null,
        ]);

        $response = $this->actingAs($user)->putJson("/closeout-rules/{$rule->id}", [
            'name' => 'Updated name',
            'order' => 2,
            'allocation_type' => 'percentage',
            'amount' => 15,
            'allocation_base' => 'remaining',
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'destination_title' => '',
            'is_active' => true,
        ]);

        $response->assertOk();
        $rule->refresh();
        $this->assertSame('Updated name', $rule->name);
        $this->assertEqualsWithDelta(15.0, (float) $rule->amount, 0.001);
        $this->assertSame('remaining', $rule->allocation_base);
    }
}
