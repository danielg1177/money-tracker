<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtRepaymentTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creditor_can_record_income_as_loan_repayment_received_matching_debtor_payment(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 80.00,
            'balance' => 80.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => true,
            'is_expense' => false,
        ]);

        $this->actingAs($creditor)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 30,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-06',
            'is_debt_repayment_received' => true,
            'debt_repayment_received_id' => $debt->id,
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '50.00',
        ]);

        $income = Transaction::query()->where('user_id', $creditor->id)->where('type', 'income')->sole();
        $expense = Transaction::query()->where('user_id', $debtor->id)->where('type', 'expense')->sole();

        $this->assertTrue($income->is_debt_payment);
        $this->assertTrue($expense->is_debt_payment);
        $this->assertSame($expense->mirror_transaction_id, $income->id);
        $this->assertSame($income->mirror_transaction_id, $expense->id);
        $this->assertSame($debt->id, (int) $income->debt_id);
        $this->assertSame($debtor->id, (int) $income->paid_by_user_id);
        $this->assertSame($debtor->id, (int) $expense->paid_by_user_id);
    }

    public function test_posting_expense_with_debt_id_creates_mirror_income_and_reduces_balance(): void
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
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 25,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-05',
            'is_split' => false,
            'description' => 'Partial pay',
            'debt_id' => $debt->id,
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '75.00',
        ]);

        $expense = Transaction::query()->where('user_id', $debtor->id)->where('type', 'expense')->sole();
        $income = Transaction::query()->where('user_id', $creditor->id)->where('type', 'income')->sole();

        $this->assertTrue($expense->is_debt_payment);
        $this->assertTrue($income->is_debt_payment);
        $this->assertSame($expense->mirror_transaction_id, $income->id);
        $this->assertSame($income->mirror_transaction_id, $expense->id);
        $this->assertSame($debt->id, (int) $expense->debt_id);

        $creditorSummary = $this->actingAs($creditor)->getJson('/month-summary?year=2026&month=5');
        $creditorSummary->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) data_get($creditorSummary->json(), 'rule_preview.basis.gross_income'), 0.001);
        $this->assertEqualsWithDelta(25.0, (float) data_get($creditorSummary->json(), 'debt_repayments.received.0.amount'), 0.001);
    }

    public function test_posting_split_expense_with_debt_id_keeps_split_and_creates_pending_split_debt(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $otherMember = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 200.00,
            'balance' => 200.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 50,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-06',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $debtor->id, 'share_percentage' => 60],
                ['user_id' => $otherMember->id, 'share_percentage' => 40],
            ],
            'description' => 'Split debt pay',
            'debt_id' => $debt->id,
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '150.00',
        ]);

        $expense = Transaction::query()->where('user_id', $debtor->id)->where('type', 'expense')->sole();
        $income = Transaction::query()->where('user_id', $creditor->id)->where('type', 'income')->sole();

        $this->assertTrue($expense->is_debt_payment);
        $this->assertTrue($expense->is_split);
        $this->assertTrue($income->is_debt_payment);
        $this->assertSame($expense->mirror_transaction_id, $income->id);
        $this->assertSame($income->mirror_transaction_id, $expense->id);

        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $expense->id,
            'user_id' => $debtor->id,
            'share_percentage' => '60.00',
            'amount' => '30.00',
        ]);
        $this->assertDatabaseHas('transaction_splits', [
            'transaction_id' => $expense->id,
            'user_id' => $otherMember->id,
            'share_percentage' => '40.00',
            'amount' => '20.00',
        ]);

        $this->assertDatabaseHas('debts', [
            'transaction_id' => $expense->id,
            'debtor_id' => $otherMember->id,
            'creditor_id' => $debtor->id,
            'amount' => '20.00',
            'balance' => '20.00',
            'is_pending_closeout' => true,
        ]);
    }

    public function test_month_summary_debt_repayments_paid_use_each_members_split_share(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $otherMember = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 50,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-21',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $debtor->id, 'share_percentage' => 60],
                ['user_id' => $otherMember->id, 'share_percentage' => 40],
            ],
            'description' => 'Split debt pay summary',
            'debt_id' => $debt->id,
        ])->assertCreated();

        $debtorPaid = $this->actingAs($debtor)->getJson('/month-summary?year=2026&month=5')->assertOk();
        $partnerPaid = $this->actingAs($otherMember)->getJson('/month-summary?year=2026&month=5')->assertOk();
        $creditorReceived = $this->actingAs($creditor)->getJson('/month-summary?year=2026&month=5')->assertOk();

        $this->assertEqualsWithDelta(30.0, (float) data_get($debtorPaid->json(), 'debt_repayments.paid.0.amount'), 0.001);
        $this->assertEqualsWithDelta(20.0, (float) data_get($partnerPaid->json(), 'debt_repayments.paid.0.amount'), 0.001);

        // Creditor still sees the gross repayment deposited (mirror income stays full principal/balance attribution).
        $this->assertEqualsWithDelta(50.0, (float) data_get($creditorReceived->json(), 'debt_repayments.received.0.amount'), 0.001);
    }

    public function test_deleting_debtor_expense_restores_balance_and_removes_partner_row(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 80.00,
            'balance' => 80.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 20,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'debt_id' => $debt->id,
        ])->assertCreated();

        $expenseId = Transaction::query()->where('user_id', $debtor->id)->where('type', 'expense')->value('id');

        $this->actingAs($debtor)->deleteJson("/transactions/{$expenseId}")->assertNoContent();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '80.00',
        ]);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_creditor_can_delete_income_mirror_and_restore_balance_once(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 50.00,
            'balance' => 50.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 15,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-12',
            'is_split' => false,
            'debt_id' => $debt->id,
        ])->assertCreated();

        $incomeId = Transaction::query()->where('user_id', $creditor->id)->where('type', 'income')->value('id');

        $this->actingAs($creditor)->deleteJson("/transactions/{$incomeId}")->assertNoContent();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '50.00',
        ]);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_debt_payment_transaction_can_be_updated_from_expense_row(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 40.00,
            'balance' => 40.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 10,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-15',
            'is_split' => false,
            'debt_id' => $debt->id,
        ])->assertCreated();

        $expense = Transaction::query()->where('user_id', $debtor->id)->where('type', 'expense')->sole();

        $this->actingAs($debtor)->putJson("/transactions/{$expense->id}", [
            'type' => 'expense',
            'amount' => 25,
            'category_id' => $category->id,
            'transaction_date' => '2026-05-16',
            'is_split' => false,
            'description' => 'edited repayment',
            'debt_id' => $debt->id,
        ])->assertOk();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '15.00',
        ]);

        $expense->refresh();
        $this->assertSame('edited repayment', $expense->description);
        $this->assertSame('2026-05-16', $expense->transaction_date->format('Y-m-d'));
        $this->assertSame(25.0, (float) $expense->amount);

        $income = Transaction::query()->where('user_id', $creditor->id)->where('type', 'income')->sole();
        $this->assertSame('edited repayment', $income->description);
        $this->assertSame('2026-05-16', $income->transaction_date->format('Y-m-d'));
        $this->assertSame(25.0, (float) $income->amount);
    }
}
