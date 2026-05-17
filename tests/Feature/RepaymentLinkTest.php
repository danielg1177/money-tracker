<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\TransactionRepaymentLink;
use App\Models\User;
use App\Services\MonthCloseoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_transaction_can_be_linked_to_expense_as_repayment(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        $expense = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 50],
            ],
        ])->assertCreated();

        $income = Transaction::query()->where('user_id', $userA->id)->where('type', 'income')->sole();
        $expense->refresh();

        $this->assertTrue($income->is_repayment);
        $this->assertTrue($expense->is_repaid);

        $mirror = Transaction::query()
            ->where('user_id', $userB->id)
            ->where('is_repayment_mirror', true)
            ->sole();

        $this->assertEqualsWithDelta(50.0, (float) $mirror->amount, 0.001);

        $this->assertDatabaseHas('transaction_repayment_links', [
            'repayment_transaction_id' => $income->id,
            'repaid_transaction_id' => $expense->id,
            'mirror_transaction_id' => $mirror->id,
            'repaid_user_id' => $userB->id,
        ]);
    }

    public function test_repayment_amounts_must_sum_to_income_amount(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        $expense = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $response = $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 30],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['repayment_links']);
    }

    public function test_deleting_repayment_income_cascades(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        $expense = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 50],
            ],
        ])->assertCreated();

        $income = Transaction::query()->where('user_id', $userA->id)->where('type', 'income')->sole();
        $mirrorId = Transaction::query()->where('user_id', $userB->id)->where('is_repayment_mirror', true)->value('id');
        $linkId = TransactionRepaymentLink::query()->where('repayment_transaction_id', $income->id)->value('id');

        $this->actingAs($userA)->deleteJson("/transactions/{$income->id}")->assertNoContent();

        $this->assertDatabaseMissing('transactions', ['id' => $income->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $mirrorId]);
        $this->assertDatabaseMissing('transaction_repayment_links', ['id' => $linkId]);

        $expense->refresh();
        $this->assertFalse($expense->is_repaid);
    }

    public function test_repaid_expense_excluded_from_totals(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 20,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $repaidExpense = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $repaidExpense->id, 'amount' => 50],
            ],
        ])->assertCreated();

        $closeout = app(MonthCloseoutService::class);

        $this->assertEqualsWithDelta(
            20.0,
            $closeout->expenseTotalTowardRemainingBasis($userA, 2026, 5),
            0.001,
        );

        $this->assertEqualsWithDelta(
            50.0,
            $closeout->expenseTotalTowardRemainingBasis($userB, 2026, 5),
            0.001,
        );
    }

    public function test_repayment_income_excluded_from_income_total(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $incomeCategory->id,
            'type' => 'income',
            'amount' => 100,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $expense = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 50],
            ],
        ])->assertCreated();

        $summary = $this->actingAs($userA)->getJson('/month-summary?year=2026&month=5');
        $summary->assertOk();
        $this->assertEqualsWithDelta(
            100.0,
            (float) data_get($summary->json(), 'rule_preview.basis.gross_income'),
            0.001,
        );
    }

    public function test_cannot_repay_already_repaid_expense(): void
    {
        $family = Family::factory()->create();
        $userA = User::factory()->create(['family_id' => $family->id]);
        $userB = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $incomeCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);

        $expense = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $userA->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 50,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
        ]);

        $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 50],
            ],
        ])->assertCreated();

        $response = $this->actingAs($userA)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 50,
            'category_id' => $incomeCategory->id,
            'transaction_date' => '2026-05-11',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $userB->id,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 50],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['repayment_links.0.transaction_id']);
    }
}
