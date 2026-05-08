<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_must_be_income_or_expense_not_both(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $this->actingAs($user)->postJson('/categories', [
            'name' => 'Ambiguous',
            'is_income' => true,
            'is_expense' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['is_income']);
    }

    public function test_category_must_be_income_or_expense_not_neither(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $this->actingAs($user)->postJson('/categories', [
            'name' => 'Neither',
            'is_income' => false,
            'is_expense' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['is_income']);
    }

    public function test_user_can_create_income_only_category(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $this->actingAs($user)->postJson('/categories', [
            'name' => 'Salary',
            'is_income' => true,
            'is_expense' => false,
        ])->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Salary',
                'is_income' => true,
                'is_expense' => false,
            ]);
    }

    public function test_user_can_create_expense_only_category(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        $this->actingAs($user)->postJson('/categories', [
            'name' => 'Groceries',
            'is_income' => false,
            'is_expense' => true,
        ])->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Groceries',
                'is_income' => false,
                'is_expense' => true,
            ]);
    }

    public function test_advance_fund_default_is_user_specific_per_category(): void
    {
        $family = Family::factory()->create();
        $headOfHousehold = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        $headFund = Fund::factory()->create([
            'user_id' => $headOfHousehold->id,
            'family_id' => $family->id,
        ]);
        $memberFund = Fund::factory()->create([
            'user_id' => $member->id,
            'family_id' => $family->id,
        ]);

        $createResponse = $this->actingAs($headOfHousehold)->postJson('/categories', [
            'name' => 'Groceries',
            'is_income' => false,
            'is_expense' => true,
            'advance_fund_id' => $headFund->id,
            'is_non_necessity_default' => false,
        ])->assertStatus(200);

        $categoryId = (int) $createResponse->json('id');

        $this->actingAs($member)->putJson("/categories/{$categoryId}", [
            'name' => 'Groceries',
            'is_income' => false,
            'is_expense' => true,
            'advance_fund_id' => $memberFund->id,
            'is_non_necessity_default' => false,
        ])->assertStatus(200);

        $this->actingAs($headOfHousehold)->getJson('/categories')
            ->assertOk()
            ->assertJsonPath('0.advance_fund_id', $headFund->id);

        $this->actingAs($member)->getJson('/categories')
            ->assertOk()
            ->assertJsonPath('0.advance_fund_id', $memberFund->id);
    }

    public function test_non_necessity_default_is_user_specific_per_category(): void
    {
        $family = Family::factory()->create();
        $headOfHousehold = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        $headFund = Fund::factory()->create([
            'user_id' => $headOfHousehold->id,
            'family_id' => $family->id,
        ]);
        $memberFund = Fund::factory()->create([
            'user_id' => $member->id,
            'family_id' => $family->id,
        ]);

        FundRule::factory()->create([
            'user_id' => $headOfHousehold->id,
            'allocation_type' => 'percentage',
            'allocation_base' => 'remaining',
            'destination_type' => 'fund',
            'destination_id' => $headFund->id,
            'is_active' => true,
            'amount' => 10,
            'order' => 1,
        ]);
        FundRule::factory()->create([
            'user_id' => $member->id,
            'allocation_type' => 'percentage',
            'allocation_base' => 'remaining',
            'destination_type' => 'fund',
            'destination_id' => $memberFund->id,
            'is_active' => true,
            'amount' => 10,
            'order' => 1,
        ]);

        $createResponse = $this->actingAs($headOfHousehold)->postJson('/categories', [
            'name' => 'Dining',
            'is_income' => false,
            'is_expense' => true,
            'advance_fund_id' => $headFund->id,
            'is_non_necessity_default' => true,
        ])->assertStatus(200);

        $categoryId = (int) $createResponse->json('id');

        $this->actingAs($member)->putJson("/categories/{$categoryId}", [
            'name' => 'Dining',
            'is_income' => false,
            'is_expense' => true,
            'advance_fund_id' => $memberFund->id,
            'is_non_necessity_default' => false,
        ])->assertStatus(200);

        $this->actingAs($headOfHousehold)->getJson('/categories')
            ->assertOk()
            ->assertJsonPath('0.is_non_necessity_default', true);

        $this->actingAs($member)->getJson('/categories')
            ->assertOk()
            ->assertJsonPath('0.is_non_necessity_default', false);
    }
}
