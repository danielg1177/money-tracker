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

    public function test_overpayment_via_pay_debt_endpoint_swings_debt_to_reversed_direction(): void
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

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 150.00,
            'description' => 'Overpayment test',
            'transaction_date' => '2026-06-01',
        ])->assertOk();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '0.00',
        ]);

        $this->assertDatabaseHas('debts', [
            'family_id' => $family->id,
            'debtor_id' => $creditor->id,
            'creditor_id' => $debtor->id,
            'amount' => '50.00',
            'balance' => '50.00',
            'is_pending_closeout' => 0,
            'is_family_debt' => 0,
        ]);

        $expense = Transaction::query()
            ->where('user_id', $debtor->id)
            ->where('type', 'expense')
            ->where('is_debt_payment', true)
            ->sole();
        $this->assertSame('150.00', $expense->amount);

        $income = Transaction::query()
            ->where('user_id', $creditor->id)
            ->where('type', 'income')
            ->where('is_debt_payment', true)
            ->sole();
        $this->assertSame('150.00', $income->amount);
    }

    public function test_exact_balance_payment_zeroes_debt_without_creating_reversed_debt(): void
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

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 80.00,
            'description' => 'Full payment',
            'transaction_date' => '2026-06-01',
        ])->assertOk();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '0.00',
        ]);

        $this->assertDatabaseCount('debts', 1);
    }

    public function test_overpayment_on_external_debt_is_rejected(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => null,
            'creditor_name' => 'Bank',
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 150.00,
            'description' => 'Overpay external',
            'transaction_date' => '2026-06-01',
        ])->assertStatus(422);

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '100.00',
        ]);
    }

    public function test_overpayment_via_expense_transaction_with_debt_id_swings_debt(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 60.00,
            'balance' => 60.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100.00,
            'category_id' => $category->id,
            'transaction_date' => '2026-06-01',
            'debt_id' => $debt->id,
            'description' => 'Overpay via transaction form',
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '0.00',
        ]);

        $this->assertDatabaseHas('debts', [
            'family_id' => $family->id,
            'debtor_id' => $creditor->id,
            'creditor_id' => $debtor->id,
            'amount' => '40.00',
            'balance' => '40.00',
        ]);
    }

    public function test_overpayment_merges_into_existing_reverse_direction_debt_instead_of_creating_second(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);

        $forwardDebt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
            'transaction_id' => null,
        ]);

        $existingReverse = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $creditor->id,
            'creditor_id' => $debtor->id,
            'amount' => 25.00,
            'balance' => 25.00,
            'is_pending_closeout' => false,
            'transaction_id' => null,
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $forwardDebt->id,
            'amount' => 150.00,
            'description' => 'Overpay into existing reverse',
            'transaction_date' => '2026-06-01',
        ])->assertOk();

        $forwardDebt->refresh();
        $existingReverse->refresh();

        $this->assertSame('0.00', $forwardDebt->balance);
        $this->assertEqualsWithDelta(75.00, (float) $existingReverse->balance, 0.01);
        $this->assertEqualsWithDelta(75.00, (float) $existingReverse->amount, 0.01);

        $this->assertSame(2, Debt::query()->where('family_id', $family->id)->count());

        $fromOriginal = collect($this->actingAs($debtor)->getJson("/debts/{$forwardDebt->id}/payments")->assertOk()->json('entries'));
        $fromReverse = collect($this->actingAs($debtor)->getJson("/debts/{$existingReverse->id}/payments")->assertOk()->json('entries'));

        $originalInitials = $fromOriginal->where('type', 'initial_value')->values();
        $originalLoan = $originalInitials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === false);
        $originalReversal = $originalInitials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === true);
        $this->assertNotNull($originalLoan);
        $this->assertNotNull($originalReversal);
        $this->assertEqualsWithDelta(100.0, (float) $originalLoan['amount'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $originalReversal['amount'], 0.01);
        $this->assertFalse($fromOriginal->contains(fn (array $row): bool => ($row['type'] ?? '') === 'initial_value' && abs((float) $row['amount'] - 25.0) < 0.01));

        $reverseInitials = $fromReverse->where('type', 'initial_value')->values();
        $reverseLoan = $reverseInitials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === false);
        $reverseReversal = $reverseInitials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === true);
        $this->assertNotNull($reverseLoan);
        $this->assertNotNull($reverseReversal);
        $this->assertEqualsWithDelta(25.0, (float) $reverseLoan['amount'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $reverseReversal['amount'], 0.01);
        $this->assertFalse($fromReverse->contains(fn (array $row): bool => ($row['type'] ?? '') === 'initial_value' && abs((float) $row['amount'] - 100.0) < 0.01));

        $originalPayload = $this->actingAs($debtor)->getJson("/debts/{$forwardDebt->id}/payments")->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, (float) $originalPayload['remaining'], 0.01);
        $reversePayload = $this->actingAs($debtor)->getJson("/debts/{$existingReverse->id}/payments")->assertOk()->json();
        $this->assertEqualsWithDelta(75.0, (float) $reversePayload['remaining'], 0.01);
    }

    public function test_payment_history_includes_both_directions_after_overpayment_reversal(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id, 'name' => 'Alex']);
        $creditor = User::factory()->create(['family_id' => $family->id, 'name' => 'Jordan']);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
            'description' => 'Original loan',
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 150.00,
            'description' => 'Overpayment test',
            'transaction_date' => '2026-06-01',
        ])->assertOk();

        $reverseDebt = Debt::query()
            ->where('family_id', $family->id)
            ->where('debtor_id', $creditor->id)
            ->where('creditor_id', $debtor->id)
            ->where('balance', '>', 0)
            ->sole();
        $this->assertSame($debt->id, $reverseDebt->reversed_from_debt_id);

        $fromOriginalPayload = $this->actingAs($debtor)->getJson("/debts/{$debt->id}/payments")->assertOk()->json();
        $fromReversePayload = $this->actingAs($debtor)->getJson("/debts/{$reverseDebt->id}/payments")->assertOk()->json();
        $fromOriginal = collect($fromOriginalPayload['entries']);
        $fromReverse = collect($fromReversePayload['entries']);

        $this->assertEqualsWithDelta(50.0, (float) $fromOriginalPayload['remaining'], 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $fromReversePayload['remaining'], 0.01);
        $this->assertSame($creditor->id, $fromOriginalPayload['remaining_debtor_id']);
        $this->assertSame($debtor->id, $fromOriginalPayload['remaining_creditor_id']);

        foreach ([$fromOriginal, $fromReverse] as $history) {
            $initials = $history->where('type', 'initial_value')->values();
            $this->assertCount(2, $initials);

            $loanStart = $initials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === false);
            $reversal = $initials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === true);
            $this->assertNotNull($loanStart);
            $this->assertNotNull($reversal);
            $this->assertEqualsWithDelta(100.0, (float) $loanStart['amount'], 0.01);
            $this->assertEqualsWithDelta(50.0, (float) $reversal['amount'], 0.01);
            $this->assertSame('loan', $loanStart['flow_kind']);
            $this->assertSame($creditor->id, $loanStart['flow_from_user_id']);
            $this->assertSame($debtor->id, $loanStart['flow_to_user_id']);
            $this->assertSame('loan', $reversal['flow_kind']);
            $this->assertSame($debtor->id, $reversal['flow_from_user_id']);
            $this->assertSame($creditor->id, $reversal['flow_to_user_id']);

            $payments = $history->where('type', 'expense')->values();
            $this->assertCount(1, $payments);
            $this->assertEqualsWithDelta(150.0, (float) $payments[0]['amount'], 0.01);
            $this->assertSame('payment', $payments[0]['flow_kind']);
            $this->assertSame($debtor->id, $payments[0]['flow_from_user_id']);
            $this->assertSame($creditor->id, $payments[0]['flow_to_user_id']);
        }

        $creditorHistory = collect($this->actingAs($creditor)->getJson("/debts/{$reverseDebt->id}/payments")->assertOk()->json('entries'));
        $this->assertCount(1, $creditorHistory->where('type', 'income'));
        $this->assertCount(2, $creditorHistory->where('type', 'initial_value'));
    }

    public function test_payment_history_does_not_include_an_independent_loan_between_the_same_pair(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id, 'name' => 'Alex']);
        $creditor = User::factory()->create(['family_id' => $family->id, 'name' => 'Jordan']);

        $first = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
            'description' => 'First loan',
        ]);
        $second = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 40.00,
            'balance' => 40.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
            'description' => 'Second loan',
        ]);

        $firstHistory = collect($this->actingAs($debtor)->getJson("/debts/{$first->id}/payments")->assertOk()->json('entries'));
        $initials = $firstHistory->where('type', 'initial_value')->values();

        $this->assertCount(1, $initials);
        $this->assertEqualsWithDelta(100.0, (float) $initials[0]['amount'], 0.01);
        $this->assertFalse($firstHistory->contains(fn (array $row): bool => (float) ($row['amount'] ?? 0) === 40.0 && ($row['type'] ?? '') === 'initial_value'));
        $this->assertSame($first->id, $initials[0]['debt_id']);

        $secondHistory = collect($this->actingAs($debtor)->getJson("/debts/{$second->id}/payments")->assertOk()->json('entries'));
        $this->assertCount(1, $secondHistory->where('type', 'initial_value'));
        $this->assertEqualsWithDelta(40.0, (float) $secondHistory->firstWhere('type', 'initial_value')['amount'], 0.01);
    }

    public function test_payment_history_includes_closeout_contributions_from_reversal_lineage(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id, 'name' => 'Alex']);
        $creditor = User::factory()->create(['family_id' => $family->id, 'name' => 'Jordan']);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 120.00,
            'balance' => 120.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'transaction_id' => null,
            'description' => 'Original loan',
            'contributions' => [
                ['month' => 5, 'year' => 2026, 'amount' => 20.0],
            ],
        ]);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 170.00,
            'description' => 'Overpayment with closeout principal',
            'transaction_date' => '2026-06-01',
        ])->assertOk();

        $reverseDebt = Debt::query()
            ->where('family_id', $family->id)
            ->where('debtor_id', $creditor->id)
            ->where('creditor_id', $debtor->id)
            ->where('balance', '>', 0)
            ->sole();

        $fromReverse = $this->actingAs($debtor)->getJson("/debts/{$reverseDebt->id}/payments")->assertOk()->json();
        $this->assertCount(1, $fromReverse['contributions']);
        $this->assertEqualsWithDelta(20.0, (float) $fromReverse['contributions'][0]['amount'], 0.01);
        $this->assertSame(5, (int) $fromReverse['contributions'][0]['month']);
        $this->assertSame($debt->id, $fromReverse['contributions'][0]['debt_id']);
        $this->assertEqualsWithDelta(50.0, (float) $fromReverse['remaining'], 0.01);

        $initials = collect($fromReverse['entries'])->where('type', 'initial_value')->values();
        $loanStart = $initials->first(fn (array $row): bool => ($row['is_direction_reversal'] ?? false) === false);
        $this->assertNotNull($loanStart);
        $this->assertEqualsWithDelta(100.0, (float) $loanStart['amount'], 0.01);
    }

    public function test_expense_with_transfer_to_user_id_creates_debt_payment_pair_and_new_debt(): void
    {
        $family = Family::factory()->create();
        $payer = User::factory()->create(['family_id' => $family->id, 'view_family_expenses' => true]);
        $recipient = User::factory()->create(['family_id' => $family->id, 'view_family_expenses' => true]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($payer)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 40,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-04',
            'is_split' => false,
            'description' => 'Venmo to family',
            'transfer_to_user_id' => $recipient->id,
        ])->assertCreated();

        $debt = Debt::query()
            ->where('family_id', $family->id)
            ->where('debtor_id', $payer->id)
            ->where('creditor_id', $recipient->id)
            ->where('is_family_debt', false)
            ->sole();
        $this->assertSame('40.00', (string) $debt->amount);
        $this->assertSame('0.00', (string) $debt->balance);

        $expense = Transaction::query()->where('user_id', $payer->id)->where('type', 'expense')->sole();
        $income = Transaction::query()->where('user_id', $recipient->id)->where('type', 'income')->sole();

        $this->assertTrue($expense->is_debt_payment);
        $this->assertTrue($income->is_debt_payment);
        $this->assertSame($debt->id, (int) $expense->debt_id);
        $this->assertSame($debt->id, (int) $income->debt_id);
        $this->assertSame($expense->mirror_transaction_id, $income->id);
        $this->assertSame($income->mirror_transaction_id, $expense->id);

        $recipientSummary = $this->actingAs($recipient)->getJson('/month-summary?year=2026&month=9')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) data_get($recipientSummary->json(), 'rule_preview.basis.gross_income'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) data_get($recipientSummary->json(), 'debt_repayments.received.0.amount'), 0.001);

        $familyExpenseTotal = collect($recipientSummary->json('family_category_totals'))
            ->where('type', 'expense')
            ->sum('total');
        $familyIncomeTotal = collect($recipientSummary->json('family_category_totals'))
            ->where('type', 'income')
            ->sum('total');
        $this->assertEqualsWithDelta(0.0, (float) $familyExpenseTotal, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $familyIncomeTotal, 0.001);
    }

    public function test_expense_with_transfer_to_user_id_pays_existing_open_debt(): void
    {
        $family = Family::factory()->create();
        $payer = User::factory()->create(['family_id' => $family->id]);
        $recipient = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $payer->id,
            'creditor_id' => $recipient->id,
            'amount' => 100.00,
            'balance' => 100.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($payer)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 25,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-04',
            'is_split' => false,
            'transfer_to_user_id' => $recipient->id,
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'id' => $debt->id,
            'balance' => '75.00',
        ]);
        $this->assertSame(1, Debt::query()->where('family_id', $family->id)->count());

        $expense = Transaction::query()->where('user_id', $payer->id)->where('type', 'expense')->sole();
        $this->assertSame($debt->id, (int) $expense->debt_id);
        $this->assertTrue($expense->is_debt_payment);
    }

    public function test_expense_with_transfer_to_user_id_does_not_reuse_zero_balance_debt(): void
    {
        $family = Family::factory()->create();
        $payer = User::factory()->create(['family_id' => $family->id]);
        $recipient = User::factory()->create(['family_id' => $family->id]);
        $paidOff = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $payer->id,
            'creditor_id' => $recipient->id,
            'amount' => 50.00,
            'balance' => 0.00,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($payer)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 20,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-04',
            'is_split' => false,
            'transfer_to_user_id' => $recipient->id,
        ])->assertCreated();

        $this->assertDatabaseHas('debts', [
            'id' => $paidOff->id,
            'balance' => '0.00',
        ]);

        $newDebt = Debt::query()
            ->where('family_id', $family->id)
            ->whereKeyNot($paidOff->id)
            ->sole();
        $this->assertSame('20.00', (string) $newDebt->amount);
        $this->assertSame('0.00', (string) $newDebt->balance);

        $expense = Transaction::query()->where('user_id', $payer->id)->where('type', 'expense')->sole();
        $this->assertSame($newDebt->id, (int) $expense->debt_id);
    }

    public function test_expense_transfer_to_user_id_rejects_self_outsider_and_debt_id_combo(): void
    {
        $family = Family::factory()->create();
        $payer = User::factory()->create(['family_id' => $family->id]);
        $recipient = User::factory()->create(['family_id' => $family->id]);
        $outsider = User::factory()->create();
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $payer->id,
            'creditor_id' => $recipient->id,
            'amount' => 80.00,
            'balance' => 80.00,
            'is_pending_closeout' => false,
        ]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $payload = [
            'type' => 'expense',
            'amount' => 20,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-04',
            'is_split' => false,
        ];

        $this->actingAs($payer)->postJson('/transactions', [
            ...$payload,
            'transfer_to_user_id' => $payer->id,
        ])->assertUnprocessable();

        $this->actingAs($payer)->postJson('/transactions', [
            ...$payload,
            'transfer_to_user_id' => $outsider->id,
        ])->assertUnprocessable();

        $this->actingAs($payer)->postJson('/transactions', [
            ...$payload,
            'debt_id' => $debt->id,
            'transfer_to_user_id' => $recipient->id,
        ])->assertUnprocessable();
    }
}
