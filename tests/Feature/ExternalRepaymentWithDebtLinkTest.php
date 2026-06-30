<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalRepaymentWithDebtLinkTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $user;

    private Category $expenseCategory;

    private Category $incomeCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->family = Family::factory()->create();
        $this->user = User::factory()->create(['family_id' => $this->family->id]);
        $this->expenseCategory = Category::factory()->create([
            'family_id' => $this->family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $this->incomeCategory = Category::factory()->create([
            'family_id' => $this->family->id,
            'is_expense' => false,
            'is_income' => true,
        ]);
    }

    /**
     * Creates a past expense owned by the user that is not yet repaid.
     */
    private function createUnrepaidExpense(float $amount = 3000.00): Transaction
    {
        return Transaction::query()->create([
            'family_id' => $this->family->id,
            'user_id' => $this->user->id,
            'category_id' => $this->expenseCategory->id,
            'type' => 'expense',
            'amount' => $amount,
            'transaction_date' => '2026-06-01',
            'is_split' => false,
            'is_repaid' => false,
        ]);
    }

    public function test_income_can_combine_external_repayment_and_new_debt_link(): void
    {
        $expense = $this->createUnrepaidExpense(3000.00);

        $response = $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 3000.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'income_debt_mode' => 'new',
            'income_new_is_interfamily' => false,
            'income_new_creditor_name' => 'First National Bank',
            'income_new_interest_enabled' => false,
            'is_external_repayment_mode' => true,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 3000.00],
            ],
        ]);

        $response->assertCreated();

        $income = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'income')
            ->sole();

        $expense->refresh();

        // Debt linked to the income
        $this->assertNotNull($income->debt_id, 'Income should be linked to a debt record.');
        $debt = Debt::query()->find($income->debt_id);
        $this->assertNotNull($debt);
        $this->assertEquals('First National Bank', $debt->creditor_name);
        $this->assertEqualsWithDelta(3000.00, (float) $debt->balance, 0.01);

        // External repayment links created
        $this->assertTrue((bool) $income->is_repayment, 'Income should be flagged is_repayment.');
        $this->assertTrue((bool) $expense->is_repaid, 'Linked expense should be flagged is_repaid.');

        $this->assertDatabaseHas('transaction_repayment_links', [
            'repayment_transaction_id' => $income->id,
            'repaid_transaction_id' => $expense->id,
            'mirror_transaction_id' => null,
            'is_external_repayment' => true,
        ]);
    }

    public function test_income_can_combine_external_repayment_and_existing_debt_link(): void
    {
        $expense = $this->createUnrepaidExpense(1500.00);

        $existingDebt = Debt::query()->create([
            'family_id' => $this->family->id,
            'debtor_id' => $this->user->id,
            'creditor_id' => null,
            'creditor_name' => 'Credit Union',
            'amount' => 5000.00,
            'balance' => 5000.00,
        ]);

        $response = $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 1500.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'income_debt_mode' => 'existing',
            'income_existing_debt_id' => $existingDebt->id,
            'is_external_repayment_mode' => true,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 1500.00],
            ],
        ]);

        $response->assertCreated();

        $income = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'income')
            ->sole();

        $expense->refresh();
        $existingDebt->refresh();

        // Income linked to existing debt and balance incremented
        $this->assertEquals($existingDebt->id, $income->debt_id);
        $this->assertEqualsWithDelta(6500.00, (float) $existingDebt->balance, 0.01);

        // External repayment applied
        $this->assertTrue((bool) $income->is_repayment);
        $this->assertTrue((bool) $expense->is_repaid);

        $this->assertDatabaseHas('transaction_repayment_links', [
            'repayment_transaction_id' => $income->id,
            'repaid_transaction_id' => $expense->id,
            'is_external_repayment' => true,
        ]);
    }

    public function test_external_repayment_with_debt_excludes_income_from_closeout_gross(): void
    {
        $expense = $this->createUnrepaidExpense(3000.00);

        $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 3000.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'income_debt_mode' => 'new',
            'income_new_is_interfamily' => false,
            'income_new_creditor_name' => 'Bank Loan',
            'income_new_interest_enabled' => false,
            'is_external_repayment_mode' => true,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 3000.00],
            ],
        ])->assertCreated();

        $income = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'income')
            ->sole();

        // is_repayment = true means excluded from closeout gross income
        $this->assertTrue((bool) $income->is_repayment);
        $this->assertFalse((bool) $income->is_debt_payment);
    }

    public function test_when_both_repayment_modes_sent_external_repayment_wins(): void
    {
        $otherUser = User::factory()->create(['family_id' => $this->family->id]);
        $expense = $this->createUnrepaidExpense(500.00);

        $response = $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 500.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'is_repayment_mode' => true,
            'repayment_for_user_id' => $otherUser->id,
            'is_external_repayment_mode' => true,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 500.00],
            ],
        ]);

        // Backend normalises: external repayment wins, family repayment mode is stripped.
        // No mirror expense should be created for the other user.
        $response->assertCreated();

        $income = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'income')
            ->sole();

        $this->assertTrue((bool) $income->is_repayment);

        // External repayment link (no mirror)
        $this->assertDatabaseHas('transaction_repayment_links', [
            'repayment_transaction_id' => $income->id,
            'is_external_repayment' => true,
        ]);

        // No mirror expense on the other user's account
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $otherUser->id,
            'is_repayment_mirror' => true,
        ]);
    }

    public function test_validation_still_requires_repayment_links_for_external_repayment(): void
    {
        $response = $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 1000.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'income_debt_mode' => 'new',
            'income_new_is_interfamily' => false,
            'income_new_creditor_name' => 'Bank',
            'income_new_interest_enabled' => false,
            'is_external_repayment_mode' => true,
            'repayment_links' => [],
        ]);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('repayment_links', $response->json('errors'));
    }

    public function test_repayment_link_amounts_must_match_income_amount_even_when_combined_with_debt(): void
    {
        $expense = $this->createUnrepaidExpense(2000.00);

        $response = $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 3000.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'income_debt_mode' => 'new',
            'income_new_is_interfamily' => false,
            'income_new_creditor_name' => 'Bank',
            'income_new_interest_enabled' => false,
            'is_external_repayment_mode' => true,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 2000.00],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('repayment_links', $response->json('errors'));
    }

    public function test_deleting_combined_income_reverses_debt_and_repayment_links(): void
    {
        $expense = $this->createUnrepaidExpense(3000.00);

        $this->actingAs($this->user)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 3000.00,
            'category_id' => $this->incomeCategory->id,
            'transaction_date' => '2026-06-15',
            'is_split' => false,
            'income_debt_mode' => 'new',
            'income_new_is_interfamily' => false,
            'income_new_creditor_name' => 'Bank Loan',
            'income_new_interest_enabled' => false,
            'is_external_repayment_mode' => true,
            'repayment_links' => [
                ['transaction_id' => $expense->id, 'amount' => 3000.00],
            ],
        ])->assertCreated();

        $income = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', 'income')
            ->sole();

        $this->actingAs($this->user)->deleteJson("/transactions/{$income->id}")
            ->assertNoContent();

        $expense->refresh();

        // Repayment link removed and expense no longer marked repaid
        $this->assertFalse((bool) $expense->is_repaid);
        $this->assertDatabaseMissing('transaction_repayment_links', [
            'repayment_transaction_id' => $income->id,
        ]);

        // Income row deleted
        $this->assertDatabaseMissing('transactions', ['id' => $income->id]);
    }
}
