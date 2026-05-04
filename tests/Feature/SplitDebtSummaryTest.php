<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitDebtSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_debt_summary_includes_nested_transaction_category(): void
    {
        $family = Family::factory()->create();
        $user1 = User::factory()->create(['family_id' => $family->id]);
        $user2 = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Groceries',
        ]);

        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        $this->actingAs($user1)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100.00,
            'description' => 'Weekly shop',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user1->id, 'share_percentage' => 50],
                ['user_id' => $user2->id, 'share_percentage' => 50],
            ],
        ])->assertStatus(201);

        $response = $this->actingAs($user1)->getJson("/split-debt-summary?year={$year}&month={$month}");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data);

        $txn = $data[0]['transactions'][0];
        $this->assertSame('Groceries', $txn['transaction']['category']['name']);
        $this->assertSame('Weekly shop', $txn['transaction']['description']);
    }

    public function test_split_debt_summary_allows_null_transaction_description(): void
    {
        $family = Family::factory()->create();
        $user1 = User::factory()->create(['family_id' => $family->id]);
        $user2 = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Utilities',
        ]);

        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        $this->actingAs($user1)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 80.00,
            'description' => null,
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user1->id, 'share_percentage' => 50],
                ['user_id' => $user2->id, 'share_percentage' => 50],
            ],
        ])->assertStatus(201);

        $response = $this->actingAs($user1)->getJson("/split-debt-summary?year={$year}&month={$month}");

        $response->assertStatus(200);
        $txn = $response->json()[0]['transactions'][0];
        $this->assertSame('Utilities', $txn['transaction']['category']['name']);
        $this->assertNull($txn['transaction']['description']);
    }
}
