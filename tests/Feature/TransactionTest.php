<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
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
            'debt_id' => $debt->id,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $creditor->id,
            'type' => 'income',
            'is_debt_payment' => true,
            'debt_id' => $debt->id,
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

    public function test_transactions_index_includes_own_and_split_participations_only(): void
    {
        $family = Family::factory()->create();
        $user1 = User::factory()->create(['family_id' => $family->id]);
        $user2 = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create(['family_id' => $family->id]);

        $this->actingAs($user2)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 25.00,
            'description' => 'Solo expense',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
        ])->assertStatus(201);

        $soloOtherMemberId = Transaction::query()->latest('id')->value('id');

        $this->actingAs($user2)->postJson('/transactions', [
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

        $splitSharedId = Transaction::query()->latest('id')->value('id');

        $idsForUser1 = collect($this->actingAs($user1)->getJson('/transactions')->json())->pluck('id')->all();
        $this->assertNotContains($soloOtherMemberId, $idsForUser1);
        $this->assertContains($splitSharedId, $idsForUser1);

        $idsForUser2 = collect($this->actingAs($user2)->getJson('/transactions')->json())->pluck('id')->all();
        $this->assertContains($soloOtherMemberId, $idsForUser2);
        $this->assertContains($splitSharedId, $idsForUser2);
    }

    public function test_transactions_index_includes_debt_payment_rows(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create(['family_id' => $family->id]);

        $normalTransaction = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 25.00,
            'is_debt_payment' => false,
            'transaction_date' => now()->toDateString(),
        ]);

        $debtPayment = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 10.00,
            'is_debt_payment' => true,
            'transaction_date' => now()->toDateString(),
        ]);

        $ids = collect($this->actingAs($user)->getJson('/transactions')->json())->pluck('id')->all();

        $this->assertContains($normalTransaction->id, $ids);
        $this->assertContains($debtPayment->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_transactions_index_shows_split_debt_payment_once_for_creditor_and_debtor(): void
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
            'is_pending_closeout' => false,
            'is_family_debt' => false,
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 40.00,
            'description' => 'Split pay',
            'split_with_user_id' => $creditor->id,
            'split_percentage' => 50,
        ])->assertOk();

        $paymentIds = Transaction::query()
            ->where('is_debt_payment', true)
            ->where('debt_id', $debt->id)
            ->pluck('id');

        $this->assertCount(2, $paymentIds);

        $creditorTxIds = collect($this->actingAs($creditor)->getJson('/transactions')->json())->pluck('id');
        $this->assertCount(1, $creditorTxIds->intersect($paymentIds)->all());
        $this->assertTrue(
            Transaction::query()->whereKey($creditorTxIds->intersect($paymentIds)->first())->where('type', 'income')->where('user_id', $creditor->id)->exists()
        );

        $debtorTxIds = collect($this->actingAs($debtor)->getJson('/transactions')->json())->pluck('id');
        $this->assertCount(1, $debtorTxIds->intersect($paymentIds)->all());
        $this->assertTrue(
            Transaction::query()->whereKey($debtorTxIds->intersect($paymentIds)->first())->where('type', 'expense')->where('user_id', $debtor->id)->exists()
        );
    }

    public function test_debt_payment_history_returns_one_row_per_inter_family_payment(): void
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
            'is_pending_closeout' => false,
            'is_family_debt' => false,
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 25.00,
            'description' => 'Single pay',
        ])->assertOk();

        $debtorHistory = $this->actingAs($debtor)->getJson("/debts/{$debt->id}/payments")->json();
        $creditorHistory = $this->actingAs($creditor)->getJson("/debts/{$debt->id}/payments")->json();

        $debtorExpenseRows = array_values(array_filter($debtorHistory, fn ($row) => ($row['type'] ?? '') === 'expense'));
        $this->assertCount(1, $debtorExpenseRows);
        $this->assertEqualsWithDelta(25.0, (float) $debtorExpenseRows[0]['amount'], 0.001);
        $this->assertCount(1, array_values(array_filter($debtorHistory, fn ($row) => ($row['type'] ?? '') === 'initial_value')));

        $creditorIncomeRows = array_values(array_filter($creditorHistory, fn ($row) => ($row['type'] ?? '') === 'income'));
        $this->assertCount(1, $creditorIncomeRows);
        $this->assertEqualsWithDelta(25.0, (float) $creditorIncomeRows[0]['amount'], 0.001);
        $this->assertCount(1, array_values(array_filter($creditorHistory, fn ($row) => ($row['type'] ?? '') === 'initial_value')));

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->fresh()->id,
            'amount' => 10.00,
            'description' => 'Second pay',
        ])->assertOk();

        $twoPays = $this->actingAs($debtor)->getJson("/debts/{$debt->id}/payments")->json();
        $twoPayExpenses = collect($twoPays)->where('type', 'expense')->pluck('amount')->map(fn ($a) => round((float) $a, 2))->sort()->values()->all();
        $this->assertSame([10.0, 25.0], $twoPayExpenses);
        $this->assertCount(1, collect($twoPays)->where('type', 'initial_value'));
        $this->assertCount(3, $twoPays);
    }

    public function test_income_transaction_strips_split_and_advance_even_when_sent(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $peer = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => true,
            'is_expense' => false,
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 200,
            'type' => 'income',
            'transaction_date' => now()->toDateString(),
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user->id, 'share_percentage' => 50],
                ['user_id' => $peer->id, 'share_percentage' => 50],
            ],
            'advance_fund_id' => $fund->id,
        ]);

        $response->assertStatus(201);

        $transaction = Transaction::query()->latest('id')->first();
        $this->assertSame('income', $transaction->type);
        $this->assertFalse((bool) $transaction->is_split);
        $this->assertNull($transaction->advance_fund_id);
        $this->assertSame(0, $transaction->splits()->count());
        $this->assertSame(0, Debt::query()->where('transaction_id', $transaction->id)->count());
    }

    public function test_category_without_expense_stores_no_split_default_or_advance_fund(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $peer = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'family_id' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/categories', [
            'name' => 'Salary',
            'icon' => '💰',
            'is_income' => true,
            'is_expense' => false,
            'is_split_default' => true,
            'split_default' => [
                ['user_id' => $user->id, 'share_percentage' => 50],
                ['user_id' => $peer->id, 'share_percentage' => 50],
            ],
            'advance_fund_id' => $fund->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'is_split_default' => false,
                'advance_fund_id' => null,
                'split_default' => null,
            ]);
    }
}
