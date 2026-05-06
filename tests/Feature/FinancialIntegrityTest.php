<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Five end-to-end financial integrity tests.
 *
 * Each test spans multiple months and exercises the full stack — transactions,
 * splits, pending debts, month closeout, fund allocations, advance-fund
 * settlements, title savings, fund borrowing/repayment, and the bank-balance
 * tracker — while asserting that the computed bank balance never drifts from
 * what a user would see on a real bank statement.
 *
 * Arithmetic contract (verified in every test):
 *   computed_balance = anchor
 *       + SUM(income transactions on/after anchor date)
 *       − SUM(expense transactions on/after anchor date)
 *       − SUM(completed title savings on/after anchor date)
 *
 * Fund closeout allocations to a virtual fund bucket do NOT create transactions
 * and therefore do NOT affect the computed balance — the money conceptually
 * stays in the user's bank account until physically moved (title savings).
 */
class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 1
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Two-member family (Alice = HOH, Bob = member).
     *
     * January scenario:
     *  • Alice earns $4,000 salary.
     *  • Alice pays a $300 restaurant bill split 50/50 with Bob.
     *    Alice's bank is debited the full $300 (she fronted it).
     *    Bob's $150 share becomes a PENDING split debt.
     *  • Attempting to pay the pending debt before month close is rejected (422).
     *
     * After both members soft-close and Alice hard-closes January:
     *  • The pending $150 debt is consolidated into a confirmed debt.
     *
     * February: Bob pays Alice the full $150.
     *  • Alice receives a $150 income transaction.
     *  • Alice's computed balance rises from $6,700 to $6,850.
     *  • The debt balance reaches zero.
     */
    public function test_split_expense_pending_debt_blocks_payment_until_hard_close_then_settles(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 5));

        $family = Family::factory()->create();
        $alice = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $bob = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        // Alice sets her bank balance anchor at $3,000 on January 5.
        $this->actingAs($alice)->putJson('/bank-balance', [
            'bank_balance' => 3000.00,
            'bank_balance_enabled' => true,
        ])->assertOk();

        // Alice earns $4,000 salary on January 5.
        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 4000.00,
            'description' => 'January salary',
            'transaction_date' => '2026-01-05',
            'is_split' => false,
        ])->assertCreated();

        // Computed balance: $3,000 + $4,000 = $7,000.
        $bankStep1 = $this->actingAs($alice)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(7000.00, $bankStep1->json('computed_balance'), 0.01);

        Carbon::setTestNow(Carbon::create(2026, 1, 10));

        // Alice pays a $300 restaurant bill split 50/50 with Bob.
        // She fronts the full $300; Bob owes her $150 as a pending split debt.
        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 300.00,
            'description' => 'Restaurant dinner',
            'transaction_date' => '2026-01-10',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $bob->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        // Alice's bank debited full $300 → $7,000 − $300 = $6,700.
        $midBalance = $this->actingAs($alice)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(6700.00, $midBalance->json('computed_balance'), 0.01);

        // Bob's pending split debt: $150, is_pending_closeout = true.
        $pendingDebt = Debt::query()
            ->where('debtor_id', $bob->id)
            ->where('creditor_id', $alice->id)
            ->where('is_pending_closeout', true)
            ->firstOrFail();
        $this->assertEqualsWithDelta(150.00, (float) $pendingDebt->balance, 0.01);

        // Attempting to pay a pending split debt must be rejected.
        $this->actingAs($bob)->postJson('/debts/pay', [
            'debt_id' => $pendingDebt->id,
            'amount' => 150.00,
            'description' => 'Early payment attempt',
        ])->assertUnprocessable();

        Carbon::setTestNow(Carbon::create(2026, 1, 31));

        // Both members soft-close January.
        $this->actingAs($alice)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 1])->assertOk();
        $this->actingAs($bob)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 1])->assertOk();

        // Alice (HOH) triggers the hard-close.
        $this->actingAs($alice)->postJson('/closeout/hard-close', ['year' => 2026, 'month' => 1])->assertOk();

        // Pending debt is deleted; a confirmed debt now exists — Bob owes Alice $150.
        $this->assertDatabaseMissing('debts', ['id' => $pendingDebt->id]);

        $confirmedDebt = Debt::query()
            ->where('debtor_id', $bob->id)
            ->where('creditor_id', $alice->id)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->firstOrFail();
        $this->assertEqualsWithDelta(150.00, (float) $confirmedDebt->balance, 0.01);

        Carbon::setTestNow(Carbon::create(2026, 2, 5));

        // Bob pays Alice the full $150 confirmed debt on February 5.
        $this->actingAs($bob)->postJson('/debts/pay', [
            'debt_id' => $confirmedDebt->id,
            'amount' => 150.00,
            'description' => 'Paying my restaurant share',
        ])->assertOk();

        $confirmedDebt->refresh();
        $this->assertEqualsWithDelta(0.00, (float) $confirmedDebt->balance, 0.01);

        // Alice receives a $150 income transaction (debt repayment).
        // Computed balance: $6,700 + $150 = $6,850.
        $finalBalance = $this->actingAs($alice)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(6850.00, $finalBalance->json('computed_balance'), 0.01);

        // Delta breakdown: $4,000 salary + $150 debt income = $4,150 income; $300 expense.
        $this->assertEqualsWithDelta(4150.00, $finalBalance->json('delta.income'), 0.01);
        $this->assertEqualsWithDelta(300.00, $finalBalance->json('delta.expense'), 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 2
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Single-member family (Sarah = HOH), so soft-close auto-triggers hard-close.
     *
     * March scenario:
     *  • Sarah earns $5,000 salary.
     *  • $300 medical expense tagged as advance against Emergency Savings fund.
     *  • $400 regular grocery expense.
     *
     * Month closeout applies two fund rules:
     *  1. 10% of gross income ($500) → Emergency Savings balance increases.
     *  2. Fixed $200 from remaining pool → title saving "Annual IRA" created.
     *
     * Advance settlement deducts $300 from Emergency Savings.
     * Final fund balance: $600 (initial) + $500 (rule) − $300 (advance) = $800.
     *
     * Key bank balance integrity assertions:
     *  • Fund allocation to Emergency Savings does NOT create a transaction
     *    → bank balance unchanged by the allocation.
     *  • Advance settlement does NOT create a transaction either
     *    → bank balance already captured the $300 expense transaction.
     *  • Completing the title saving DOES deduct $200 from the bank balance
     *    (user physically transferred money to IRA).
     */
    public function test_single_member_fund_allocation_advance_settlement_and_title_savings_bank_balance(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 1));

        $family = Family::factory()->create();
        $sarah = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);

        // Emergency Savings fund with a $600 existing balance.
        $emergencyFund = Fund::factory()->create([
            'user_id' => $sarah->id,
            'balance' => 600.00,
            'name' => 'Emergency Savings',
        ]);

        // Medical expense category wired to Emergency Savings as an advance target.
        $medicalCategory = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Medical',
            'is_expense' => true,
            'is_income' => false,
            'advance_fund_id' => $emergencyFund->id,
        ]);

        // Rule 1: 10% of gross income → Emergency Savings.
        FundRule::query()->create([
            'user_id' => $sarah->id,
            'fund_id' => null,
            'name' => '10% Emergency',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $emergencyFund->id,
            'destination_title' => null,
        ]);

        // Rule 2: Fixed $200 from remaining → title "Annual IRA".
        FundRule::query()->create([
            'user_id' => $sarah->id,
            'fund_id' => null,
            'name' => 'Annual IRA',
            'order' => 2,
            'allocation_type' => 'fixed',
            'amount' => 200,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'title',
            'destination_id' => null,
            'destination_title' => 'Annual IRA',
        ]);

        // Anchor: $8,000 on March 1.
        $this->actingAs($sarah)->putJson('/bank-balance', [
            'bank_balance' => 8000.00,
            'bank_balance_enabled' => true,
        ])->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 3, 5));
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 5000.00,
            'description' => 'March salary',
            'transaction_date' => '2026-03-05',
            'is_split' => false,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 3, 15));
        // Medical expense ($300) — tagged as advance against Emergency Savings.
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 300.00,
            'description' => 'Doctor visit',
            'transaction_date' => '2026-03-15',
            'is_split' => false,
            'category_id' => $medicalCategory->id,
            'advance_fund_id' => $emergencyFund->id,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 3, 20));
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 400.00,
            'description' => 'Groceries',
            'transaction_date' => '2026-03-20',
            'is_split' => false,
        ])->assertCreated();

        // Pre-close bank: $8,000 + $5,000 − $300 − $400 = $12,300.
        $preClose = $this->actingAs($sarah)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(12300.00, $preClose->json('computed_balance'), 0.01);

        Carbon::setTestNow(Carbon::create(2026, 3, 31));

        // Soft-close (single-member → auto hard-close).
        $closeResponse = $this->actingAs($sarah)->postJson('/closeout/soft-close', [
            'year' => 2026,
            'month' => 3,
        ])->assertOk();
        $this->assertTrue($closeResponse->json('auto_hard_closed'));

        // Emergency Savings: $600 + $500 (10% of $5,000) − $300 (advance) = $800.
        $emergencyFund->refresh();
        $this->assertEqualsWithDelta(800.00, (float) $emergencyFund->balance, 0.01);

        // Title saving "Annual IRA" must exist for $200, not yet completed.
        // Gross: $5,000 | gross rule: $500 | soloExpenses: $700 | remaining: $3,800 → rule $200.
        $titleSaving = CloseoutTitleSaving::query()
            ->where('user_id', $sarah->id)
            ->where('title', 'Annual IRA')
            ->where('year', 2026)
            ->where('month', 3)
            ->firstOrFail();
        $this->assertEqualsWithDelta(200.00, (float) $titleSaving->amount, 0.01);
        $this->assertFalse((bool) $titleSaving->is_completed);

        // Bank balance is UNCHANGED by the fund allocation and advance settlement
        // (neither creates a transaction — money still in checking, just virtually earmarked).
        $postClose = $this->actingAs($sarah)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(12300.00, $postClose->json('computed_balance'), 0.01);
        $this->assertEqualsWithDelta(0.00, $postClose->json('delta.title_savings_completed'), 0.01);

        Carbon::setTestNow(Carbon::create(2026, 4, 5));

        // Sarah marks the IRA title saving as completed (physically transferred to IRA).
        $this->actingAs($sarah)->postJson("/title-savings/{$titleSaving->id}/complete")->assertOk();

        // Bank balance: $12,300 − $200 = $12,100.
        // Real-world check: $8,000 anchor + $5,000 income − $700 expenses − $200 IRA transfer = $12,100 ✓
        $afterTitle = $this->actingAs($sarah)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(12100.00, $afterTitle->json('computed_balance'), 0.01);
        $this->assertEqualsWithDelta(200.00, $afterTitle->json('delta.title_savings_completed'), 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 3
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Three-member family (Alice = HOH, Bob, Carol).
     *
     * February:
     *  • Alice pays $300 restaurant split 40/30/30 (Alice/Bob/Carol).
     *    → Bob owes Alice $90 (pending), Carol owes Alice $90 (pending).
     *  • Bob pays $200 groceries split 50/50 (Bob/Alice).
     *    → Alice owes Bob $100 (pending).
     *
     * After February hard-close — netting per person-pair:
     *  • Bob/Alice: Bob owes $90, Alice owes $100 → NET: Alice owes Bob $10 confirmed.
     *  • Carol/Alice: Carol owes $90 → confirmed debt $90.
     *
     * March:
     *  • Alice pays $150 utilities split 50/50 (Alice/Carol).
     *    → Carol owes Alice $75 (pending).
     *
     * After March hard-close:
     *  • Carol's existing confirmed $90 debt grows to $165 (accumulates, not replaced).
     *
     * Alice then pays her $10 debt to Bob → Bob's debt balance reaches zero.
     */
    public function test_three_member_split_debt_netting_accumulates_across_two_month_closeouts(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $bob = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);
        $carol = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        // ── February 2026 ──────────────────────────────────────────────────────

        // Alice pays $300 restaurant (40% Alice, 30% Bob, 30% Carol).
        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 300.00,
            'description' => 'Restaurant dinner',
            'transaction_date' => '2026-02-10',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 40],
                ['user_id' => $bob->id, 'share_percentage' => 30],
                ['user_id' => $carol->id, 'share_percentage' => 30],
            ],
        ])->assertCreated();

        // Bob pays $200 groceries (50% Bob, 50% Alice).
        $this->actingAs($bob)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 200.00,
            'description' => 'Grocery run',
            'transaction_date' => '2026-02-18',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $bob->id, 'share_percentage' => 50],
                ['user_id' => $alice->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        // Three pending debts created: Bob→Alice $90, Carol→Alice $90, Alice→Bob $100.
        $this->assertSame(3, Debt::query()->where('is_pending_closeout', true)->count());

        // All three soft-close February; Alice hard-closes.
        $this->actingAs($alice)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 2])->assertOk();
        $this->actingAs($bob)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 2])->assertOk();
        $this->actingAs($carol)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 2])->assertOk();
        $this->actingAs($alice)->postJson('/closeout/hard-close', ['year' => 2026, 'month' => 2])->assertOk();

        // All pending debts deleted after consolidation.
        $this->assertSame(0, Debt::query()->where('is_pending_closeout', true)->count());

        // Bob owed Alice $90 but Alice owed Bob $100 → net $10: Alice owes Bob.
        $aliceOwsBob = Debt::query()
            ->where('debtor_id', $alice->id)
            ->where('creditor_id', $bob->id)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->firstOrFail();
        $this->assertEqualsWithDelta(10.00, (float) $aliceOwsBob->balance, 0.01);

        // Carol owed Alice $90 only → confirmed debt $90.
        $carolOwsAlice = Debt::query()
            ->where('debtor_id', $carol->id)
            ->where('creditor_id', $alice->id)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->firstOrFail();
        $this->assertEqualsWithDelta(90.00, (float) $carolOwsAlice->balance, 0.01);

        // ── March 2026 ──────────────────────────────────────────────────────────

        // Alice pays $150 utilities (50% Alice, 50% Carol).
        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 150.00,
            'description' => 'Utilities',
            'transaction_date' => '2026-03-15',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $carol->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        // One new pending debt: Carol owes Alice $75.
        $this->assertSame(1, Debt::query()->where('is_pending_closeout', true)->count());

        // All three soft-close March; Alice hard-closes.
        $this->actingAs($alice)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 3])->assertOk();
        $this->actingAs($bob)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 3])->assertOk();
        $this->actingAs($carol)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 3])->assertOk();
        $this->actingAs($alice)->postJson('/closeout/hard-close', ['year' => 2026, 'month' => 3])->assertOk();

        // Carol's March $75 pending debt merges into her existing $90 confirmed debt → $165.
        $carolOwsAlice->refresh();
        $this->assertEqualsWithDelta(165.00, (float) $carolOwsAlice->amount, 0.01);
        $this->assertEqualsWithDelta(165.00, (float) $carolOwsAlice->balance, 0.01);

        // ── April: Alice pays her $10 net debt to Bob ───────────────────────────

        Carbon::setTestNow(Carbon::create(2026, 4, 1));

        $this->actingAs($alice)->postJson('/debts/pay', [
            'debt_id' => $aliceOwsBob->id,
            'amount' => 10.00,
            'description' => 'Settling February net balance',
        ])->assertOk();

        $aliceOwsBob->refresh();
        $this->assertEqualsWithDelta(0.00, (float) $aliceOwsBob->balance, 0.01);

        // Bob received a $10 income transaction (mirror of Alice's expense).
        $this->assertDatabaseHas('transactions', [
            'user_id' => $bob->id,
            'type' => 'income',
            'amount' => '10.00',
            'is_debt_payment' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 4
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fund borrow / repay — single-member family (John = HOH).
     *
     * John starts with a $1,500 bank anchor and an Emergency Fund holding $2,000.
     *
     * Sequence:
     *  1. Borrow $500 from fund → income transaction +$500, fund −$500.
     *     Bank: $1,500 + $500 = $2,000 ✓ (money moved from savings to checking).
     *  2. Spend $300 on car repair → bank: $2,000 − $300 = $1,700.
     *  3. Repay $200 to fund → expense transaction −$200, fund +$200.
     *     Bank: $1,700 − $200 = $1,500 ✓ (money moved from checking back to savings).
     *
     * Fund after all operations: $2,000 − $500 + $200 = $1,700.
     * Remaining fund debt: $500 − $200 = $300.
     *
     * Real-world reconciliation:
     *   $1,500 (anchor) + $500 (borrow) − $300 (repair) − $200 (repay) = $1,500 ✓
     */
    public function test_fund_borrow_and_repayment_tracks_correctly_in_bank_balance(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 1));

        $family = Family::factory()->create();
        $john = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);

        $emergencyFund = Fund::factory()->create([
            'user_id' => $john->id,
            'balance' => 2000.00,
            'name' => 'Emergency Fund',
        ]);

        // Anchor at $1,500 on May 1.
        $this->actingAs($john)->putJson('/bank-balance', [
            'bank_balance' => 1500.00,
            'bank_balance_enabled' => true,
        ])->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 5, 3));

        // Borrow $500: fund decrements, income transaction created, debt created.
        $this->actingAs($john)->postJson("/funds/{$emergencyFund->id}/borrow", [
            'amount' => 500.00,
            'description' => 'Emergency car repair',
        ])->assertCreated();

        $emergencyFund->refresh();
        $this->assertEqualsWithDelta(1500.00, (float) $emergencyFund->balance, 0.01);

        $fundDebt = Debt::query()
            ->where('debtor_id', $john->id)
            ->where('fund_id', $emergencyFund->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(500.00, (float) $fundDebt->balance, 0.01);

        // Bank: $1,500 + $500 borrow income = $2,000.
        $afterBorrow = $this->actingAs($john)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(2000.00, $afterBorrow->json('computed_balance'), 0.01);

        Carbon::setTestNow(Carbon::create(2026, 5, 8));

        // Regular expense $300 (car repair parts).
        $this->actingAs($john)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 300.00,
            'description' => 'Car repair parts',
            'transaction_date' => '2026-05-08',
            'is_split' => false,
        ])->assertCreated();

        // Bank: $2,000 − $300 = $1,700.
        $afterExpense = $this->actingAs($john)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(1700.00, $afterExpense->json('computed_balance'), 0.01);

        Carbon::setTestNow(Carbon::create(2026, 5, 12));

        // Repay $200 to Emergency Fund.
        $this->actingAs($john)->postJson("/debts/{$fundDebt->id}/repay-fund", [
            'amount' => 200.00,
        ])->assertOk();

        // Fund: $1,500 (after borrow) + $200 (repay) = $1,700.
        $emergencyFund->refresh();
        $this->assertEqualsWithDelta(1700.00, (float) $emergencyFund->balance, 0.01);

        // Debt: $500 − $200 = $300 remaining.
        $fundDebt->refresh();
        $this->assertEqualsWithDelta(300.00, (float) $fundDebt->balance, 0.01);

        // Bank: $1,700 − $200 repayment expense = $1,500.
        // Real-world: $1,500 + $500 − $300 − $200 = $1,500 ✓
        $finalBank = $this->actingAs($john)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(1500.00, $finalBank->json('computed_balance'), 0.01);
        // Income delta = $500 (borrow income only — no salary recorded this test).
        $this->assertEqualsWithDelta(500.00, $finalBank->json('delta.income'), 0.01);
        // Expense delta = $300 (repair) + $200 (fund repay) = $500.
        $this->assertEqualsWithDelta(500.00, $finalBank->json('delta.expense'), 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Test 5
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Full two-month household simulation — Sarah (HOH) and Mike (member).
     *
     * Setup:
     *  • Emergency Savings fund: $1,000 starting balance.
     *  • Groceries category → advance against Emergency Savings.
     *  • Closeout rule 1: 10% gross income → Emergency Savings.
     *  • Closeout rule 2: $300 fixed from remaining → title "Car Repair Reserve".
     *
     * March:
     *  • Sarah earns $3,500. Bank anchor set at $5,000 on March 1.
     *  • Sarah buys $250 groceries (advance fund tagged).
     *  • Mike earns $2,800 (does not affect Sarah's bank).
     *  • Sarah pays $120 electricity bill split 60/40 (Sarah/Mike).
     *    Sarah fronts full $120; Mike owes $48 pending.
     *  • Mike pays $80 internet split 50/50 (Mike/Sarah).
     *    Mike fronts full $80; Sarah owes $40 pending.
     *
     * Sarah's pre-close bank: $5,000 + $3,500 − $250 − $120 = $8,130.
     *
     * March closeout (both soft-close; Sarah hard-closes):
     *  • Rule 1: 10% × $3,500 = $350 → Emergency Savings.
     *  • soloExpenses = $250, splitExpenses = $72 (60% of $120) + $40 (50% of $80) = $112.
     *  • totalExpenses = $362, remaining = $3,500 − $350 − $362 = $2,788.
     *  • Rule 2: $300 → title "Car Repair Reserve".
     *  • Advance settlement: Emergency Savings −$250.
     *  • Emergency Savings final: $1,000 + $350 − $250 = $1,100.
     *  • Net split debt after netting: Mike owes Sarah $8 ($48 − $40).
     *  • Bank unchanged by fund ops (no transactions created for allocation / settlement).
     *
     * April:
     *  • Sarah earns another $3,500.
     *  • Mike pays Sarah the $8 net debt.
     *  • Sarah marks "Car Repair Reserve" as completed.
     *
     * Sarah's final computed balance:
     *   $5,000 + ($3,500 + $8 + $3,500) income − ($250 + $120) expense − $300 title = $11,338.
     *
     * Real-world check:
     *   $5,000 + $7,000 earned + $8 received − $370 spent − $300 transferred = $11,338 ✓
     */
    public function test_complete_two_month_household_simulation_bank_balance_stays_accurate(): void
    {
        // ── Setup ──────────────────────────────────────────────────────────────

        Carbon::setTestNow(Carbon::create(2026, 3, 1));

        $family = Family::factory()->create();
        $sarah = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'head_of_household',
        ]);
        $mike = User::factory()->create([
            'family_id' => $family->id,
            'role' => 'member',
        ]);

        $emergencySavings = Fund::factory()->create([
            'user_id' => $sarah->id,
            'balance' => 1000.00,
            'name' => 'Emergency Savings',
        ]);

        $groceriesCategory = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Groceries',
            'is_expense' => true,
            'is_income' => false,
            'advance_fund_id' => $emergencySavings->id,
        ]);

        FundRule::query()->create([
            'user_id' => $sarah->id,
            'fund_id' => null,
            'name' => '10% Emergency',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $emergencySavings->id,
            'destination_title' => null,
        ]);

        FundRule::query()->create([
            'user_id' => $sarah->id,
            'fund_id' => null,
            'name' => 'Car Repair Reserve',
            'order' => 2,
            'allocation_type' => 'fixed',
            'amount' => 300,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'title',
            'destination_id' => null,
            'destination_title' => 'Car Repair Reserve',
        ]);

        // Bank anchor: $5,000 on March 1.
        $this->actingAs($sarah)->putJson('/bank-balance', [
            'bank_balance' => 5000.00,
            'bank_balance_enabled' => true,
        ])->assertOk();

        // ── March transactions ─────────────────────────────────────────────────

        Carbon::setTestNow(Carbon::create(2026, 3, 5));
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 3500.00,
            'description' => 'March salary',
            'transaction_date' => '2026-03-05',
            'is_split' => false,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 3, 10));
        // Grocery spend tagged as advance against Emergency Savings.
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 250.00,
            'description' => 'Weekly groceries',
            'transaction_date' => '2026-03-10',
            'is_split' => false,
            'category_id' => $groceriesCategory->id,
            'advance_fund_id' => $emergencySavings->id,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 3, 15));
        // Mike's salary does not affect Sarah's bank balance.
        $this->actingAs($mike)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 2800.00,
            'description' => 'March salary',
            'transaction_date' => '2026-03-15',
            'is_split' => false,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 3, 20));
        // Sarah pays $120 electricity (60% Sarah / 40% Mike).
        // Sarah's bank is debited the full $120; Mike's $48 share becomes a pending debt.
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 120.00,
            'description' => 'Electricity bill',
            'transaction_date' => '2026-03-20',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $sarah->id, 'share_percentage' => 60],
                ['user_id' => $mike->id, 'share_percentage' => 40],
            ],
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 3, 25));
        // Mike pays $80 internet (50% Mike / 50% Sarah).
        // Mike's bank is debited the full $80; Sarah's $40 share becomes a pending debt.
        $this->actingAs($mike)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 80.00,
            'description' => 'Internet bill',
            'transaction_date' => '2026-03-25',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $mike->id, 'share_percentage' => 50],
                ['user_id' => $sarah->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        // Pre-close bank: $5,000 + $3,500 − $250 (groceries) − $120 (electricity full) = $8,130.
        // Mike's $80 internet bill is NOT Sarah's expense (Mike owns that transaction).
        $preClose = $this->actingAs($sarah)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(8130.00, $preClose->json('computed_balance'), 0.01);
        $this->assertEqualsWithDelta(3500.00, $preClose->json('delta.income'), 0.01);
        $this->assertEqualsWithDelta(370.00, $preClose->json('delta.expense'), 0.01);

        // ── March closeout ─────────────────────────────────────────────────────

        Carbon::setTestNow(Carbon::create(2026, 3, 31));
        $this->actingAs($sarah)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 3])->assertOk();
        $this->actingAs($mike)->postJson('/closeout/soft-close', ['year' => 2026, 'month' => 3])->assertOk();
        $this->actingAs($sarah)->postJson('/closeout/hard-close', ['year' => 2026, 'month' => 3])->assertOk();

        // Emergency Savings: $1,000 + $350 (rule 1) − $250 (advance settlement) = $1,100.
        $emergencySavings->refresh();
        $this->assertEqualsWithDelta(1100.00, (float) $emergencySavings->balance, 0.01);

        // "Car Repair Reserve" title saving created for $300.
        // Remaining = $3,500 − $350 (gross) − $362 (solo $250 + split $72 + split $40) = $2,788 ≥ $300.
        $titleSaving = CloseoutTitleSaving::query()
            ->where('user_id', $sarah->id)
            ->where('title', 'Car Repair Reserve')
            ->where('year', 2026)
            ->where('month', 3)
            ->firstOrFail();
        $this->assertEqualsWithDelta(300.00, (float) $titleSaving->amount, 0.01);
        $this->assertFalse((bool) $titleSaving->is_completed);

        // Split debt netting: Mike owed Sarah $48, Sarah owed Mike $40 → net $8 Mike owes Sarah.
        $netDebt = Debt::query()
            ->where('debtor_id', $mike->id)
            ->where('creditor_id', $sarah->id)
            ->where('is_pending_closeout', false)
            ->whereNull('transaction_id')
            ->firstOrFail();
        $this->assertEqualsWithDelta(8.00, (float) $netDebt->balance, 0.01);

        // Bank unchanged: fund allocation and advance settlement generate no transactions.
        // Title saving not yet completed → zero effect on bank balance.
        $postClose = $this->actingAs($sarah)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(8130.00, $postClose->json('computed_balance'), 0.01);
        $this->assertEqualsWithDelta(0.00, $postClose->json('delta.title_savings_completed'), 0.01);

        // Verify fund allocations are NOT counted as income in the bank balance.
        // If they were, income delta would be > $3,500; it must remain $3,500.
        $this->assertEqualsWithDelta(3500.00, $postClose->json('delta.income'), 0.01);

        // ── April transactions ─────────────────────────────────────────────────

        Carbon::setTestNow(Carbon::create(2026, 4, 5));
        $this->actingAs($sarah)->postJson('/transactions', [
            'type' => 'income',
            'amount' => 3500.00,
            'description' => 'April salary',
            'transaction_date' => '2026-04-05',
            'is_split' => false,
        ])->assertCreated();

        Carbon::setTestNow(Carbon::create(2026, 4, 10));
        // Mike pays Sarah the $8 net split debt.
        $this->actingAs($mike)->postJson('/debts/pay', [
            'debt_id' => $netDebt->id,
            'amount' => 8.00,
            'description' => 'Settling March split balance',
        ])->assertOk();

        $netDebt->refresh();
        $this->assertEqualsWithDelta(0.00, (float) $netDebt->balance, 0.01);

        Carbon::setTestNow(Carbon::create(2026, 4, 12));
        // Sarah physically transfers $300 to car repair account → marks title saving complete.
        $this->actingAs($sarah)->postJson("/title-savings/{$titleSaving->id}/complete")->assertOk();

        // ── Final bank balance verification ─────────────────────────────────────

        // Income on/after March 1:
        //   $3,500 (Mar salary) + $8 (debt repayment received Apr 10) + $3,500 (Apr salary) = $7,008.
        // Expense on/after March 1:
        //   $250 (groceries) + $120 (electricity) = $370.
        // Completed title savings on/after March 1:
        //   $300 (completed Apr 12).
        // Computed: $5,000 + $7,008 − $370 − $300 = $11,338.
        $finalBank = $this->actingAs($sarah)->getJson('/bank-balance')->assertOk();
        $this->assertEqualsWithDelta(11338.00, $finalBank->json('computed_balance'), 0.01);
        $this->assertEqualsWithDelta(7008.00, $finalBank->json('delta.income'), 0.01);
        $this->assertEqualsWithDelta(370.00, $finalBank->json('delta.expense'), 0.01);
        $this->assertEqualsWithDelta(300.00, $finalBank->json('delta.title_savings_completed'), 0.01);

        // Mike's debt is fully settled.
        $this->assertEqualsWithDelta(0.00, (float) $netDebt->balance, 0.01);

        // Verify that the fund closeout allocation ($350 to Emergency Savings) did NOT inflate
        // Sarah's income delta. Income must equal the $3,500 + $8 + $3,500 transaction sum only.
        $this->assertEqualsWithDelta(7008.00, $finalBank->json('delta.income'), 0.01);
    }
}
