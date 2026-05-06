<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthCloseoutTransactionDateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Closeout-created debt payment should use today's date when closing current month.
     */
    public function test_closeout_debt_payment_uses_today_for_current_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-05-01',
            'is_split' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $user->id,
            'creditor_id' => null,
            'amount' => 300,
            'balance' => 300,
            'creditor_name' => 'Credit Card',
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => null,
            'name' => 'Pay debt',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 100,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'debt',
            'destination_id' => $debt->id,
            'destination_title' => null,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'expense',
            'is_debt_payment' => true,
            'is_closeout_initiated' => true,
            'transaction_date' => '2026-05-10 00:00:00',
        ]);
    }

    public function test_closeout_fund_allocation_creates_closeout_expense_transaction(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'family_id' => null, 'balance' => 0]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => false,
            'is_expense' => true,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-05-01',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'Fund contribution',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 120,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'destination_title' => null,
            'closeout_expense_category_id' => $expenseCategory->id,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 120.00,
            'category_id' => $expenseCategory->id,
            'is_closeout_initiated' => true,
            'is_debt_payment' => false,
            'transaction_date' => '2026-05-31 00:00:00',
        ]);

        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'type' => 'closeout_allocation',
            'amount' => 120.00,
        ]);
    }

    public function test_title_completion_creates_and_reverses_closeout_expense_transaction(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => false,
            'is_expense' => true,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-05-01',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => null,
            'name' => 'Title reserve',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 150,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'title',
            'destination_id' => null,
            'destination_title' => 'Car Maintenance',
            'closeout_expense_category_id' => $expenseCategory->id,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $titleSavingId = (int) CloseoutTitleSaving::query()->value('id');
        $this->assertNotSame(0, $titleSavingId);

        $this->actingAs($user)->postJson("/title-savings/{$titleSavingId}/complete")->assertOk();

        $completionTransactionId = (int) CloseoutTitleSaving::query()->value('completion_transaction_id');
        $this->assertNotSame(0, $completionTransactionId);

        $this->assertDatabaseHas('transactions', [
            'id' => $completionTransactionId,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 150.00,
            'category_id' => $expenseCategory->id,
            'is_closeout_initiated' => true,
            'description' => 'Completed title saving: Car Maintenance',
        ]);

        $this->actingAs($user)->deleteJson("/title-savings/{$titleSavingId}/complete")->assertOk();

        $this->assertDatabaseMissing('transactions', [
            'id' => $completionTransactionId,
        ]);
    }

    /**
     * Closeout-created debt payment should use month-end for past months.
     */
    public function test_closeout_debt_payment_uses_month_end_for_past_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-05-01',
            'is_split' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $user->id,
            'creditor_id' => null,
            'amount' => 300,
            'balance' => 300,
            'creditor_name' => 'Credit Card',
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => null,
            'name' => 'Pay debt',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 100,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'debt',
            'destination_id' => $debt->id,
            'destination_title' => null,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'expense',
            'is_debt_payment' => true,
            'is_closeout_initiated' => true,
            'transaction_date' => '2026-05-31 00:00:00',
        ]);
    }

    public function test_hard_close_consolidates_pending_split_debt_with_null_transaction_id(): void
    {
        $family = Family::factory()->create();
        $manager = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $manager->id,
            'year' => 2026,
            'month' => 4,
            'closed_at' => now(),
        ]);
        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $member->id,
            'year' => 2026,
            'month' => 4,
            'closed_at' => now(),
        ]);

        $orphan = Debt::query()->create([
            'family_id' => $family->id,
            'debtor_id' => $member->id,
            'creditor_id' => $manager->id,
            'fund_id' => null,
            'transaction_id' => null,
            'amount' => 75,
            'balance' => 75,
            'description' => 'Split stub',
            'is_pending_closeout' => true,
        ]);

        $this->actingAs($manager)->postJson('/closeout/hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseMissing('debts', [
            'id' => $orphan->id,
        ]);

        $this->assertDatabaseHas('debts', [
            'family_id' => $family->id,
            'debtor_id' => $member->id,
            'creditor_id' => $manager->id,
            'is_pending_closeout' => false,
            'amount' => 75,
            'balance' => 75,
        ]);
    }

    public function test_hard_close_applies_interest_through_closed_month_end_even_when_closed_late(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $manager = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $manager->id,
            'year' => 2026,
            'month' => 2,
            'closed_at' => now(),
        ]);
        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $member->id,
            'year' => 2026,
            'month' => 2,
            'closed_at' => now(),
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $member->id,
            'creditor_id' => $manager->id,
            'amount' => 1000,
            'balance' => 1000,
            'interest_enabled' => true,
            'interest_rate' => 12.00,
            'loan_received_date' => '2026-01-01',
            'interest_last_applied_at' => null,
            'is_pending_closeout' => false,
        ]);

        $this->actingAs($manager)->postJson('/closeout/hard-close', [
            'year' => 2026,
            'month' => 2,
        ])->assertOk();

        $debt->refresh();

        $this->assertEqualsWithDelta(1009.21, (float) $debt->balance, 0.01);
        $this->assertEqualsWithDelta(1000.00, (float) $debt->amount, 0.01);
        $this->assertEquals('2026-02-28', $debt->interest_last_applied_at?->toDateString());
        $this->assertNotEmpty($debt->interest_accruals);
        $this->assertEqualsWithDelta(9.21, (float) ($debt->interest_accruals[0]['amount'] ?? 0), 0.01);
        $this->assertEquals('2026-02-28', $debt->interest_accruals[0]['applied_at'] ?? null);
    }

    public function test_hard_close_interest_uses_daily_accrual_and_mid_month_payment_reduces_later_interest(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 15, 12, 0, 0));

        $family = Family::factory()->create();
        $debtor = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);
        $manager = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);

        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'year' => 2026,
            'month' => 3,
            'closed_at' => now(),
        ]);
        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $manager->id,
            'year' => 2026,
            'month' => 3,
            'closed_at' => now(),
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => null,
            'creditor_name' => 'Bank Loan',
            'amount' => 1000.00,
            'balance' => 900.00,
            'interest_enabled' => true,
            'interest_rate' => 12.00,
            'loan_received_date' => '2026-03-01',
            'interest_last_applied_at' => null,
            'is_pending_closeout' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'type' => 'expense',
            'amount' => 100.00,
            'description' => 'Mid-month payment',
            'transaction_date' => '2026-03-15',
            'is_debt_payment' => true,
            'debt_id' => $debt->id,
            'paid_by_user_id' => $debtor->id,
            'is_closeout_initiated' => false,
            'is_split' => false,
            'split_data' => null,
        ]);

        $this->actingAs($manager)->postJson('/closeout/hard-close', [
            'year' => 2026,
            'month' => 3,
        ])->assertOk();

        $debt->refresh();

        $this->assertEqualsWithDelta(909.63, (float) $debt->balance, 0.01);
        $this->assertEqualsWithDelta(1000.00, (float) $debt->amount, 0.01);
        $this->assertEquals('2026-03-31', $debt->interest_last_applied_at?->toDateString());
        $this->assertNotEmpty($debt->interest_accruals);
        $this->assertEqualsWithDelta(9.63, (float) ($debt->interest_accruals[0]['amount'] ?? 0), 0.01);

        $history = $this->actingAs($debtor)->getJson("/debts/{$debt->id}/payments")->assertOk();
        $history->assertJsonFragment([
            'type' => 'interest_accrual',
            'description' => 'Monthly Interest Accrued',
        ]);
    }

    public function test_remaining_percentage_rules_use_shared_remaining_base_during_closeout(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fundA = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);
        $fundB = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 5000,
            'description' => 'Salary',
            'transaction_date' => '2026-05-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 1000,
            'description' => 'Monthly expenses',
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_debt_payment' => false,
            'is_closeout_initiated' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fundA->id,
            'name' => 'Half to fund A',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fundA->id,
            'destination_title' => null,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fundB->id,
            'name' => 'Half to fund B',
            'order' => 2,
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fundB->id,
            'destination_title' => null,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 5,
        ])->assertOk();

        $fundA->refresh();
        $fundB->refresh();

        $this->assertEqualsWithDelta(2000.00, (float) $fundA->balance, 0.01);
        $this->assertEqualsWithDelta(2000.00, (float) $fundB->balance, 0.01);
    }

    public function test_month_summary_preview_uses_shared_remaining_base_for_percentage_rules(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fundA = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);
        $fundB = Fund::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 5000,
            'description' => 'Salary',
            'transaction_date' => '2026-05-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 1000,
            'description' => 'Monthly expenses',
            'transaction_date' => '2026-05-10',
            'is_split' => false,
            'is_debt_payment' => false,
            'is_closeout_initiated' => false,
        ]);

        $ruleA = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fundA->id,
            'name' => 'Half to fund A',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fundA->id,
            'destination_title' => null,
        ]);

        $ruleB = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fundB->id,
            'name' => 'Half to fund B',
            'order' => 2,
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fundB->id,
            'destination_title' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/month-summary?year=2026&month=5')->assertOk();
        $rules = collect($response->json('rule_preview.rules'));

        $this->assertEqualsWithDelta(4000.00, (float) $response->json('rule_preview.basis.remaining_after_expenses'), 0.01);
        $this->assertEqualsWithDelta(2000.00, (float) $rules->firstWhere('rule_id', $ruleA->id)['projected_amount'], 0.01);
        $this->assertEqualsWithDelta(2000.00, (float) $rules->firstWhere('rule_id', $ruleB->id)['projected_amount'], 0.01);
    }

    public function test_month_summary_fund_movements_do_not_include_prior_month_closeout_by_created_at(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create(['user_id' => $user->id, 'family_id' => null, 'balance' => 0]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'April salary',
            'transaction_date' => '2026-04-15',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'April closeout contribution',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 100,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'destination_title' => null,
        ]);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $aprilSummary = $this->actingAs($user)->getJson('/month-summary?year=2026&month=4')->assertOk();
        $this->assertEqualsWithDelta(100.00, (float) data_get($aprilSummary->json(), 'fund_movements.totals.in'), 0.01);

        $maySummary = $this->actingAs($user)->getJson('/month-summary?year=2026&month=5')->assertOk();
        $this->assertEqualsWithDelta(0.00, (float) data_get($maySummary->json(), 'fund_movements.totals.in'), 0.01);
        $this->assertCount(0, data_get($maySummary->json(), 'fund_movements.by_fund', []));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
