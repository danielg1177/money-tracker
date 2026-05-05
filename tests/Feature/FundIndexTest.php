<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_funds_returns_200_when_family_fund_exists_created_by_another_member(): void
    {
        $family = Family::factory()->create();
        $owner = User::factory()->create(['family_id' => $family->id]);
        $member = User::factory()->create(['family_id' => $family->id]);

        Fund::factory()->create([
            'user_id' => $owner->id,
            'family_id' => $family->id,
            'name' => 'Household Fund',
        ]);

        $response = $this->actingAs($member)->getJson('/funds');

        $response->assertOk();
        $rows = $response->json();
        $this->assertCount(1, $rows);
        $this->assertSame('family', $rows[0]['scope']);
        $this->assertSame('Household Fund', $rows[0]['name']);
    }

    public function test_get_funds_merges_personal_and_family_funds_without_error(): void
    {
        $family = Family::factory()->create();
        $owner = User::factory()->create(['family_id' => $family->id]);
        $member = User::factory()->create(['family_id' => $family->id]);

        Fund::factory()->create([
            'user_id' => $member->id,
            'family_id' => null,
            'name' => 'My savings',
        ]);
        Fund::factory()->create([
            'user_id' => $owner->id,
            'family_id' => $family->id,
            'name' => 'Shared',
        ]);

        $response = $this->actingAs($member)->getJson('/funds');

        $response->assertOk();
        $scopes = collect($response->json())->pluck('scope')->sort()->values()->all();
        $this->assertSame(['family', 'personal'], $scopes);
    }

    public function test_family_fund_created_by_same_user_appears_once_in_index(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => $family->id,
            'name' => 'Joint Vacation',
        ]);

        $response = $this->actingAs($user)->getJson('/funds');

        $response->assertOk();
        $rows = $response->json();
        $this->assertCount(1, $rows);
        $this->assertSame('family', $rows[0]['scope']);
        $this->assertSame('Joint Vacation', $rows[0]['name']);
    }

    public function test_get_funds_includes_user_on_each_movement_for_history(): void
    {
        $user = User::factory()->create(['name' => 'Alex Contributor']);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
            'name' => 'Rainy day',
        ]);
        FundMovement::query()->create([
            'fund_id' => $fund->id,
            'user_id' => $user->id,
            'type' => 'allocation',
            'amount' => '25.00',
            'description' => 'Test allocation',
        ]);

        $response = $this->actingAs($user)->getJson('/funds');

        $response->assertOk();
        $movements = $response->json()[0]['movements'];
        $this->assertCount(1, $movements);
        $this->assertArrayHasKey('user', $movements[0]);
        $this->assertSame('Alex Contributor', $movements[0]['user']['name']);
    }
}
