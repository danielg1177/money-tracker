<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\MonthHardClose;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosedMonthMutationLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_soft_closed_month_blocks_transaction_creation(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => true,
            'is_expense' => false,
        ]);

        $this->softClose($family, $user, 2026, 7);

        $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100,
            'type' => 'income',
            'transaction_date' => '2026-07-10',
            'is_split' => false,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This month is soft-closed for an affected user and cannot be changed.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_family_hard_closed_month_blocks_transaction_creation(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_income' => true,
            'is_expense' => false,
        ]);

        $this->hardClose($family, $user, 2026, 7);

        $this->actingAs($user)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100,
            'type' => 'income',
            'transaction_date' => '2026-07-10',
            'is_split' => false,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This month is hard-closed and cannot be changed.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_soft_closed_month_blocks_transaction_update_and_delete(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);
        $transaction = Transaction::factory()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 25,
            'transaction_date' => '2026-07-04',
        ]);

        $this->softClose($family, $user, 2026, 7);

        $this->actingAs($user)->putJson("/transactions/{$transaction->id}", [
            'category_id' => $category->id,
            'amount' => 30,
            'type' => 'expense',
            'transaction_date' => '2026-07-04',
            'is_split' => false,
        ])->assertStatus(422);

        $this->actingAs($user)->deleteJson("/transactions/{$transaction->id}")
            ->assertStatus(422);

        $transaction->refresh();
        $this->assertEqualsWithDelta(25.0, (float) $transaction->amount, 0.01);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_split_transaction_creation_is_blocked_when_participant_soft_closed_month(): void
    {
        $family = Family::factory()->create();
        $payer = User::factory()->create(['family_id' => $family->id]);
        $participant = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create([
            'family_id' => $family->id,
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->softClose($family, $participant, 2026, 7);

        $this->actingAs($payer)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100,
            'type' => 'expense',
            'transaction_date' => '2026-07-10',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $payer->id, 'share_percentage' => 50],
                ['user_id' => $participant->id, 'share_percentage' => 50],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_splits', 0);
    }

    public function test_debt_payment_is_blocked_when_creditor_soft_closed_payment_month(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);
        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 100,
            'balance' => 100,
            'is_pending_closeout' => false,
        ]);

        $this->softClose($family, $creditor, 2026, 7);

        $this->actingAs($debtor)->postJson('/debts/pay', [
            'debt_id' => $debt->id,
            'amount' => 20,
            'transaction_date' => '2026-07-11',
        ])->assertStatus(422);

        $debt->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $debt->balance, 0.01);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_fund_borrow_is_blocked_when_current_month_is_soft_closed(): void
    {
        Carbon::setTestNow('2026-07-12 10:00:00');

        try {
            $family = Family::factory()->create();
            $user = User::factory()->create(['family_id' => $family->id]);
            $fund = Fund::factory()->create([
                'user_id' => $user->id,
                'balance' => 100,
            ]);

            $this->softClose($family, $user, 2026, 7);

            $this->actingAs($user)->postJson("/funds/{$fund->id}/borrow", [
                'amount' => 25,
            ])->assertStatus(422);

            $fund->refresh();
            $this->assertEqualsWithDelta(100.0, (float) $fund->balance, 0.01);
            $this->assertDatabaseCount('transactions', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function softClose(Family $family, User $user, int $year, int $month): void
    {
        MonthSoftClose::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'year' => $year,
            'month' => $month,
            'closed_at' => now(),
        ]);
    }

    private function hardClose(Family $family, User $user, int $year, int $month): void
    {
        MonthHardClose::query()->create([
            'family_id' => $family->id,
            'year' => $year,
            'month' => $month,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
        ]);
    }
}
