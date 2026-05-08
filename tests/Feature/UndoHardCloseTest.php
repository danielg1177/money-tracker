<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UndoHardCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_undo_hard_close_requires_authentication(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $response = $this->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ]);

        $this->assertContains($response->status(), [302, 401]);
    }

    public function test_undo_hard_close_requires_can_manage_family(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
            'is_admin' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();
        $this->actingAs($member)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();
        $this->actingAs($headUser)->postJson('/closeout/hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->actingAs($member)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertForbidden();
    }

    public function test_undo_hard_close_returns_422_when_no_hard_close_exists(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertStatus(422);
    }

    public function test_undo_hard_close_deletes_hard_close_record(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseHas('month_hard_closes', [
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 4,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseMissing('month_hard_closes', [
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 4,
        ]);
        $this->assertDatabaseMissing('month_soft_closes', [
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 4,
        ]);
    }

    public function test_undo_hard_close_reverses_fund_allocations(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $headUser->id,
            'family_id' => null,
            'balance' => 0,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $headUser->id,
            'fund_id' => $fund->id,
            'name' => 'Fund fixed',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 200,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'destination_title' => null,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $fund->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $fund->balance, 0.01);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $fund->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $fund->balance, 0.01);

        $closeoutMovementCount = FundMovement::query()
            ->where('fund_id', $fund->id)
            ->where('type', 'closeout_allocation')
            ->where('description', 'like', '%(2026-04)%')
            ->count();
        $this->assertSame(0, $closeoutMovementCount);

        $closeoutTransactionCount = Transaction::query()
            ->where('user_id', $headUser->id)
            ->where('is_closeout_initiated', true)
            ->whereYear('transaction_date', 2026)
            ->whereMonth('transaction_date', 4)
            ->count();
        $this->assertSame(0, $closeoutTransactionCount);
    }

    public function test_undo_hard_close_reverses_debt_allocations(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $headUser->id,
            'creditor_id' => null,
            'amount' => 500,
            'balance' => 500,
            'creditor_name' => 'Bank',
        ]);

        FundRule::query()->create([
            'user_id' => $headUser->id,
            'fund_id' => null,
            'name' => 'Debt fixed',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 100,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'debt',
            'destination_id' => $debt->id,
            'destination_title' => null,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $debt->refresh();
        $this->assertEqualsWithDelta(400.0, (float) $debt->balance, 0.01);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $debt->refresh();
        $this->assertEqualsWithDelta(500.0, (float) $debt->balance, 0.01);
    }

    public function test_undo_hard_close_deletes_title_savings(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $headUser->id,
            'fund_id' => null,
            'name' => 'Title fixed',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 100,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'title',
            'destination_id' => null,
            'destination_title' => 'Emergency',
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseHas('closeout_title_savings', [
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 4,
            'title' => 'Emergency',
        ]);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseMissing('closeout_title_savings', [
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 4,
        ]);
    }

    public function test_undo_hard_close_deletes_completed_title_saving_transaction(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        FundRule::query()->create([
            'user_id' => $headUser->id,
            'fund_id' => null,
            'name' => 'Title fixed',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 100,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'title',
            'destination_id' => null,
            'destination_title' => 'Emergency',
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $titleSaving = CloseoutTitleSaving::query()
            ->where('family_id', $family->id)
            ->where('year', 2026)
            ->where('month', 4)
            ->firstOrFail();

        $this->actingAs($headUser)->postJson("/title-savings/{$titleSaving->id}/complete")->assertOk();

        $titleSaving->refresh();
        $completionTransactionId = (int) $titleSaving->completion_transaction_id;
        $this->assertNotSame(0, $completionTransactionId);
        $this->assertDatabaseHas('transactions', ['id' => $completionTransactionId]);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseMissing('closeout_title_savings', [
            'family_id' => $family->id,
            'year' => 2026,
            'month' => 4,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'id' => $completionTransactionId,
        ]);
    }

    public function test_undo_hard_close_reverses_interest_accruals(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $headUser->id,
            'creditor_id' => null,
            'amount' => 1200,
            'balance' => 1200,
            'interest_enabled' => true,
            'interest_rate' => 12.00,
            'loan_received_date' => '2026-01-01',
            'interest_last_applied_at' => null,
            'interest_accruals' => null,
            'creditor_name' => 'Bank',
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $debt->refresh();
        $this->assertGreaterThan(1200.0, (float) $debt->balance);
        $accrualsAfterClose = $debt->interest_accruals ?? [];
        $hasAprilEntry = collect($accrualsAfterClose)->contains(fn ($entry) => (int) ($entry['year'] ?? 0) === 2026 && (int) ($entry['month'] ?? 0) === 4);
        $this->assertTrue($hasAprilEntry);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $debt->refresh();
        $this->assertEqualsWithDelta(1200.0, (float) $debt->balance, 0.01);
        $this->assertTrue(empty($debt->interest_accruals ?? []));
    }

    public function test_undo_hard_close_reverses_advance_settlement(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => false,
            'is_expense' => true,
        ]);
        $fund = Fund::factory()->create([
            'user_id' => $headUser->id,
            'family_id' => null,
            'balance' => 500,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'type' => 'income',
            'amount' => 1000,
            'description' => 'Salary',
            'transaction_date' => '2026-04-10',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $headUser->id,
            'category_id' => $expenseCategory->id,
            'type' => 'expense',
            'amount' => 200,
            'description' => 'Advanced expense',
            'transaction_date' => '2026-04-12',
            'is_split' => false,
            'advance_fund_id' => $fund->id,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $fund->refresh();
        $this->assertEqualsWithDelta(300.0, (float) $fund->balance, 0.01);
        $this->assertDatabaseHas('fund_movements', [
            'fund_id' => $fund->id,
            'user_id' => $headUser->id,
            'type' => 'advance_settlement',
        ]);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $fund->refresh();
        $this->assertEqualsWithDelta(500.0, (float) $fund->balance, 0.01);
        $this->assertSame(0, FundMovement::query()
            ->where('fund_id', $fund->id)
            ->where('user_id', $headUser->id)
            ->where('type', 'advance_settlement')
            ->count());
    }

    public function test_undo_hard_close_recreates_pending_split_debts_and_reverses_confirmed_debt(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $family = Family::factory()->create();
        $headUser = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => false,
        ]);
        $member = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
            'is_admin' => false,
        ]);
        $expenseCategory = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => false,
            'is_expense' => true,
        ]);

        $transactionResponse = $this->actingAs($headUser)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Shared expense',
            'transaction_date' => '2026-04-20',
            'category_id' => $expenseCategory->id,
            'is_split' => true,
            'split_data' => [
                ['user_id' => $headUser->id, 'share_percentage' => 60],
                ['user_id' => $member->id, 'share_percentage' => 40],
            ],
        ])->assertCreated();

        $transactionId = (int) $transactionResponse->json('id');
        $transaction = Transaction::query()->findOrFail($transactionId);

        $this->assertDatabaseHas('debts', [
            'transaction_id' => $transaction->id,
            'debtor_id' => $member->id,
            'creditor_id' => $headUser->id,
            'is_pending_closeout' => true,
            'amount' => 40.00,
        ]);

        $this->actingAs($headUser)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();
        $this->actingAs($member)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();
        $this->actingAs($headUser)->postJson('/closeout/hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseMissing('debts', [
            'transaction_id' => $transaction->id,
            'debtor_id' => $member->id,
            'creditor_id' => $headUser->id,
            'is_pending_closeout' => true,
        ]);

        $confirmedDebt = Debt::query()
            ->where('family_id', $family->id)
            ->where('debtor_id', $member->id)
            ->where('creditor_id', $headUser->id)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->firstOrFail();

        $this->assertNotEmpty($confirmedDebt->contributions);
        $hasAprilContribution = collect($confirmedDebt->contributions)->contains(fn ($entry) => (int) ($entry['year'] ?? 0) === 2026 && (int) ($entry['month'] ?? 0) === 4 && (float) ($entry['amount'] ?? 0) === 40.0);
        $this->assertTrue($hasAprilContribution);

        $this->actingAs($headUser)->postJson('/closeout/undo-hard-close', [
            'year' => 2026,
            'month' => 4,
        ])->assertOk();

        $this->assertDatabaseMissing('debts', [
            'id' => $confirmedDebt->id,
        ]);
        $this->assertDatabaseHas('debts', [
            'transaction_id' => $transaction->id,
            'debtor_id' => $member->id,
            'creditor_id' => $headUser->id,
            'is_pending_closeout' => true,
            'amount' => 40.00,
            'balance' => 40.00,
        ]);
    }
}
