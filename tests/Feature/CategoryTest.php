<?php

namespace Tests\Feature;

use App\Models\Family;
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
        ])->assertStatus(201)
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
        ])->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Groceries',
                'is_income' => false,
                'is_expense' => true,
            ]);
    }
}
