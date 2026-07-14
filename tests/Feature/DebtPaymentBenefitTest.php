<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\MonthHardClose;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtPaymentBenefitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     family: Family,
     *     debtor: User,
     *     creditor: User,
     *     debt: Debt,
     *     expenseCategory: Category,
     *     income: Transaction,
     *     expense: Transaction
     * }
     */
    private function createDebtPaymentPair(float $paymentAmount = 2000.00, float $debtBalance = 3000.00): array
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => $debtBalance,
            'balance' => $debtBalance,
            'is_pending_closeout' => false,
        ]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Rent',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => $paymentAmount,
            'category_id' => $expenseCategory->id,
            'transaction_date' => '2026-07-10',
            'description' => 'Covered rent',
            'is_split' => false,
            'debt_id' => $debt->id,
        ])->assertCreated();

        $expense = Transaction::query()
            ->where('user_id', $debtor->id)
            ->where('type', 'expense')
            ->where('is_debt_payment', true)
            ->sole();
        $income = Transaction::query()
            ->where('user_id', $creditor->id)
            ->where('type', 'income')
            ->where('is_debt_payment', true)
            ->sole();

        return compact('family', 'debtor', 'creditor', 'debt', 'expenseCategory', 'income', 'expense');
    }

    public function test_creditor_can_record_benefit_expense_for_debt_repayment_income(): void
    {
        [
            'creditor' => $creditor,
            'debt' => $debt,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair();

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'description' => 'Rent',
            'is_split' => false,
        ])->assertCreated()
            ->assertJsonPath('is_debt_payment_benefit', true)
            ->assertJsonPath('category_id', $expenseCategory->id)
            ->assertJsonPath('amount', '2000.00')
            ->assertJsonPath('debt_payment_income_id', $income->id);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $creditor->id,
            'type' => 'expense',
            'is_debt_payment_benefit' => true,
            'debt_payment_income_id' => $income->id,
            'amount' => '2000.00',
            'category_id' => $expenseCategory->id,
        ]);

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '1000.00',
        ]);

        $this->assertSame(1, Transaction::query()->where('is_debt_payment', true)->where('type', 'income')->count());
    }

    public function test_benefit_expense_supports_split_and_creates_pending_debts(): void
    {
        [
            'family' => $family,
            'debtor' => $debtor,
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(100.00, 100.00);

        $third = User::factory()->create(['family_id' => $family->id]);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => true,
            'split_data' => [
                ['user_id' => $creditor->id, 'share_percentage' => 50],
                ['user_id' => $third->id, 'share_percentage' => 50],
            ],
        ])->assertCreated()
            ->assertJsonPath('is_split', true);

        $benefit = Transaction::query()->where('is_debt_payment_benefit', true)->sole();
        $this->assertCount(2, $benefit->splits);

        $this->assertDatabaseHas('debts', [
            'transaction_id' => $benefit->id,
            'debtor_id' => $third->id,
            'creditor_id' => $creditor->id,
            'amount' => '50.00',
            'is_pending_closeout' => true,
        ]);

        $this->assertDatabaseMissing('debts', [
            'transaction_id' => $benefit->id,
            'debtor_id' => $debtor->id,
        ]);
    }

    public function test_benefit_expense_supports_advance_and_non_necessity(): void
    {
        [
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(50.00, 50.00);

        $fund = Fund::factory()->create([
            'user_id' => $creditor->id,
            'balance' => 500,
        ]);

        FundRule::query()->create([
            'user_id' => $creditor->id,
            'fund_id' => null,
            'name' => 'Remaining to fund',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
        ]);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
            'advance_fund_id' => $fund->id,
            'is_non_necessity' => true,
        ])->assertCreated()
            ->assertJsonPath('advance_fund_id', $fund->id)
            ->assertJsonPath('is_non_necessity', true);
    }

    public function test_debtor_cannot_record_benefit_on_creditor_income(): void
    {
        [
            'debtor' => $debtor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(25.00, 25.00);

        $this->actingAs($debtor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertForbidden();
    }

    public function test_second_benefit_post_is_rejected(): void
    {
        [
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(25.00, 25.00);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertCreated();

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertStatus(422);
    }

    public function test_updating_debt_payment_amount_syncs_benefit_expense(): void
    {
        [
            'debtor' => $debtor,
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
            'expense' => $expense,
        ] = $this->createDebtPaymentPair(100.00, 200.00);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertCreated();

        $response = $this->actingAs($debtor)->putJson("/transactions/{$expense->id}", [
            'type' => 'expense',
            'amount' => 50,
            'category_id' => $expenseCategory->id,
            'transaction_date' => '2026-07-10',
            'is_split' => false,
            'debt_id' => $expense->debt_id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('transactions', [
            'is_debt_payment_benefit' => true,
            'debt_payment_income_id' => $income->id,
            'amount' => '50.00',
        ]);
    }

    public function test_deleting_debt_payment_cascades_benefit_expense(): void
    {
        [
            'debtor' => $debtor,
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
            'expense' => $expense,
        ] = $this->createDebtPaymentPair(40.00, 40.00);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertCreated();

        $this->assertDatabaseCount('transactions', 3);

        $this->actingAs($debtor)->deleteJson("/transactions/{$expense->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseMissing('transactions', [
            'is_debt_payment_benefit' => true,
        ]);
    }

    public function test_removing_benefit_leaves_debt_payment_pair_intact(): void
    {
        [
            'creditor' => $creditor,
            'debt' => $debt,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(80.00, 80.00);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertCreated();

        $this->actingAs($creditor)->deleteJson("/transactions/{$income->id}/debt-payment-benefit")
            ->assertNoContent();

        $this->assertDatabaseMissing('transactions', [
            'is_debt_payment_benefit' => true,
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $income->id,
            'is_debt_payment' => true,
            'type' => 'income',
        ]);
        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '0.00',
        ]);
    }

    public function test_soft_closed_month_blocks_benefit_mutations(): void
    {
        [
            'family' => $family,
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(60.00, 60.00);

        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $creditor->id,
            'year' => 2026,
            'month' => 7,
            'closed_at' => now(),
        ]);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This month is soft-closed for an affected user and cannot be changed.');
    }

    public function test_hard_closed_month_blocks_benefit_mutations(): void
    {
        [
            'family' => $family,
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(60.00, 60.00);

        MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 7,
            'closed_by_user_id' => $creditor->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'is_split' => false,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This month is hard-closed and cannot be changed.');
    }

    public function test_month_summary_includes_benefit_expense_in_category_totals(): void
    {
        [
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(200.00, 200.00);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'description' => 'Rent',
            'is_split' => false,
        ])->assertCreated();

        $summary = $this->actingAs($creditor)->getJson('/month-summary?year=2026&month=7')
            ->assertOk()
            ->json();

        $rentTotal = collect($summary['category_totals'] ?? [])
            ->firstWhere('category_id', $expenseCategory->id);

        $this->assertNotNull($rentTotal);
        $this->assertEqualsWithDelta(200.00, (float) $rentTotal['total'], 0.01);
    }

    public function test_benefit_can_be_updated(): void
    {
        [
            'creditor' => $creditor,
            'expenseCategory' => $expenseCategory,
            'income' => $income,
        ] = $this->createDebtPaymentPair(90.00, 90.00);

        $otherCategory = Category::factory()->create([
            'family_id' => $creditor->family_id,
            'name' => 'Utilities',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($creditor)->postJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $expenseCategory->id,
            'description' => 'Rent',
            'is_split' => false,
        ])->assertCreated();

        $this->actingAs($creditor)->putJson("/transactions/{$income->id}/debt-payment-benefit", [
            'category_id' => $otherCategory->id,
            'description' => 'Utilities',
            'is_split' => false,
        ])->assertOk()
            ->assertJsonPath('category_id', $otherCategory->id)
            ->assertJsonPath('description', 'Utilities');
    }
}
