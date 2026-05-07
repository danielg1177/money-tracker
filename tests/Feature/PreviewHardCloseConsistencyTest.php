<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewHardCloseConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_debt_destination_rule_projected_amount_capped_at_debt_balance(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 2000,
            'description' => 'Pay',
            'transaction_date' => '2026-06-01',
            'is_split' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $user->id,
            'creditor_id' => null,
            'creditor_name' => 'CreditCard',
            'amount' => 80,
            'balance' => 80,
        ]);

        $rule = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => null,
            'name' => 'Nominal paydown',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 500,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'debt',
            'destination_id' => $debt->id,
            'destination_title' => null,
        ]);

        $preview = $this->actingAs($user)->getJson('/month-summary?year=2026&month=6')->assertOk();
        $rules = collect($preview->json('rule_preview.rules'));
        $ruleRow = $rules->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($ruleRow);
        $this->assertEqualsWithDelta(500.0, (float) $ruleRow['projected_amount'], 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $ruleRow['net_after_advances'], 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $preview->json('rule_preview.basis.gross_allocations_total'), 0.01);
        $this->assertEqualsWithDelta(1920.0, (float) $preview->json('rule_preview.basis.remaining_after_expenses'), 0.01);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 6,
        ])->assertOk();

        $debt->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $debt->balance, 0.01);

        $closeoutDebtPaymentQuery = Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'expense')
            ->where('debt_id', $debt->id)
            ->where('is_closeout_initiated', true)
            ->where('amount', '>', 0);

        $this->assertSame(1, $closeoutDebtPaymentQuery->count());
        $this->assertEqualsWithDelta(80.0, (float) $closeoutDebtPaymentQuery->value('amount'), 0.01);
    }

    public function test_preview_and_hard_close_agree_on_remaining_when_debt_is_fully_paid(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 14, 12, 0, 0));

        $family = Family::factory()->create();
        $user = User::factory()->create(['family_id' => $family->id]);
        $fund = Fund::factory()->create([
            'user_id' => $user->id,
            'balance' => 0,
            'family_id' => null,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => 5000,
            'description' => 'Pay',
            'transaction_date' => '2026-07-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'amount' => 1000,
            'description' => 'Monthly spend',
            'transaction_date' => '2026-07-05',
            'is_split' => false,
            'is_debt_payment' => false,
            'is_closeout_initiated' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $user->id,
            'creditor_id' => null,
            'creditor_name' => 'Card',
            'amount' => 200,
            'balance' => 200,
        ]);

        $debtRule = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => null,
            'name' => 'Debt rule',
            'order' => 1,
            'allocation_type' => 'fixed',
            'amount' => 300,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'debt',
            'destination_id' => $debt->id,
            'destination_title' => null,
        ]);

        $fundRule = FundRule::query()->create([
            'user_id' => $user->id,
            'fund_id' => $fund->id,
            'name' => 'Save rest',
            'order' => 2,
            'allocation_type' => 'fixed',
            'amount' => 400,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $fund->id,
            'destination_title' => null,
        ]);

        $preview = $this->actingAs($user)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $rules = collect($preview->json('rule_preview.rules'));

        $this->assertEqualsWithDelta(200.0, (float) $preview->json('rule_preview.basis.gross_allocations_total'), 0.01);
        $this->assertEqualsWithDelta(3800.0, (float) $preview->json('rule_preview.basis.remaining_after_expenses'), 0.01);

        $debtRow = $rules->firstWhere('rule_id', $debtRule->id);
        $fundRow = $rules->firstWhere('rule_id', $fundRule->id);
        $this->assertNotNull($debtRow);
        $this->assertNotNull($fundRow);
        $this->assertEqualsWithDelta(300.0, (float) $debtRow['projected_amount'], 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $debtRow['net_after_advances'], 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $fundRow['projected_amount'], 0.01);

        $this->actingAs($user)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 7,
        ])->assertOk();

        $debt->refresh();
        $fund->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $debt->balance, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $fund->balance, 0.01);
    }
}
