<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_income_transaction(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 150.00,
            'description' => 'Test income',
            'type' => 'income',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
        ])->assertStatus(201);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'family_id' => $family->id,
            'type' => 'income',
            'amount' => 150.00,
        ]);
    }

    public function test_transaction_requires_type_and_date(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100.00,
            'description' => 'Test',
            'is_split' => false,
        ])->assertStatus(422);
    }

    public function test_split_transaction_creates_debt(): void
    {
        $family = Family::factory()->create();
        $user1 = User::factory()->create(['family_id' => $family->id]);
        $user2 = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create();

        $this->actingAs($user1)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100.00,
            'description' => 'Split expense',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user1->id, 'share_percentage' => 50],
                ['user_id' => $user2->id, 'share_percentage' => 50],
            ],
        ])->assertStatus(201);

        $transaction = Transaction::latest()->first();
        $this->assertCount(2, $transaction->splits);

        $this->assertDatabaseHas('debts', [
            'debtor_id' => $user2->id,
            'creditor_id' => $user1->id,
            'amount' => 50.00,
        ]);
    }

    public function test_split_percentages_must_sum_to_100(): void
    {
        $family = Family::factory()->create();
        $user1 = User::factory()->create(['family_id' => $family->id]);
        $user2 = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create();

        $this->actingAs($user1)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100.00,
            'description' => 'Bad split',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user1->id, 'share_percentage' => 50],
                ['user_id' => $user2->id, 'share_percentage' => 40],
            ],
        ])->assertStatus(422);
    }

    public function test_pay_debt_reduces_balance(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100.00,
            'balance' => 100.00,
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 50.00,
            'description' => 'Partial payment',
        ])->assertStatus(200);

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => 50.00,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $debtor->id,
            'type' => 'expense',
            'is_debt_payment' => true,
        ]);
    }

    public function test_user_without_family_cannot_create_transaction(): void
    {
        $user = User::factory()->create(['family_id' => null]);
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100.00,
            'description' => 'Test',
            'type' => 'income',
            'transaction_date' => now()->toDateString(),
        ])->assertStatus(403);
    }
}
