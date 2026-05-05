<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Family;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
