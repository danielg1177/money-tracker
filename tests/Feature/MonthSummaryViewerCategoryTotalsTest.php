<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Fund;
use App\Models\FundRule;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthSummaryViewerCategoryTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_summary_category_totals_only_include_authenticated_user_transactions(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $incomeCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Salary',
            'is_income' => true,
            'is_expense' => false,
        ]);
        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Groceries',
            'is_expense' => true,
            'is_income' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'category_id' => $incomeCat->id,
            'type' => 'income',
            'amount' => 9999,
            'description' => 'Alice pay',
            'transaction_date' => '2026-07-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'category_id' => $expenseCat->id,
            'type' => 'expense',
            'amount' => 80,
            'description' => 'Bob shop',
            'transaction_date' => '2026-07-10',
            'is_split' => false,
            'is_debt_payment' => false,
        ]);

        $bobSummary = $this->actingAs($bob)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $aliceSummary = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=7')->assertOk();

        $bobExpenseTotal = collect($bobSummary->json('category_totals'))
            ->where('type', 'expense')
            ->sum('total');
        $bobIncomeTotal = collect($bobSummary->json('category_totals'))
            ->where('type', 'income')
            ->sum('total');

        $this->assertEqualsWithDelta(80.0, $bobExpenseTotal, 0.001);
        $this->assertEqualsWithDelta(0.0, $bobIncomeTotal, 0.001);

        $aliceIncomeTotal = collect($aliceSummary->json('category_totals'))
            ->where('type', 'income')
            ->sum('total');
        $aliceExpenseTotal = collect($aliceSummary->json('category_totals'))
            ->where('type', 'expense')
            ->sum('total');

        $this->assertEqualsWithDelta(9999.0, $aliceIncomeTotal, 0.001);
        $this->assertEqualsWithDelta(0.0, $aliceExpenseTotal, 0.001);
    }

    public function test_month_summary_category_totals_include_viewer_share_for_split_expenses(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Dining',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-07-15',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 60],
                ['user_id' => $bob->id, 'share_percentage' => 40],
            ],
        ])->assertCreated();

        $bobSummary = $this->actingAs($bob)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $bobRow = collect($bobSummary->json('category_totals'))
            ->where('type', 'expense')
            ->firstWhere('category_id', $expenseCat->id);

        $this->assertNotNull($bobRow);
        $this->assertEqualsWithDelta(40.0, (float) $bobRow['total'], 0.001);

        $aliceSummary = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $aliceRow = collect($aliceSummary->json('category_totals'))
            ->where('type', 'expense')
            ->firstWhere('category_id', $expenseCat->id);

        $this->assertNotNull($aliceRow);
        $this->assertEqualsWithDelta(60.0, (float) $aliceRow['total'], 0.001);
    }

    public function test_month_summary_merges_categorized_debt_payments_into_expense_category(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);

        $incomeCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Salary',
            'is_income' => true,
            'is_expense' => false,
        ]);
        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Misc',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 500.00,
            'balance' => 500.00,
            'is_pending_closeout' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'category_id' => $incomeCat->id,
            'type' => 'income',
            'amount' => 5000,
            'description' => 'Pay',
            'transaction_date' => '2026-08-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'category_id' => $expenseCat->id,
            'type' => 'expense',
            'amount' => 800,
            'description' => 'Other spend',
            'transaction_date' => '2026-08-05',
            'is_split' => false,
            'is_debt_payment' => false,
        ]);

        $this->actingAs($debtor)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 150,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-08-10',
            'is_split' => false,
            'description' => 'Debt pay',
            'debt_id' => $debt->id,
        ])->assertCreated();

        $summary = $this->actingAs($debtor)->getJson('/month-summary?year=2026&month=8')->assertOk();

        $this->assertNull(collect($summary->json('category_totals'))->firstWhere('category_id', -1));

        $miscRow = collect($summary->json('category_totals'))
            ->where('type', 'expense')
            ->firstWhere('category_id', $expenseCat->id);

        $this->assertNotNull($miscRow);
        $this->assertEqualsWithDelta(950.0, (float) $miscRow['total'], 0.001);

        $this->assertEqualsWithDelta(950.0, (float) $summary->json('rule_preview.basis.total_expenses'), 0.001);
    }

    public function test_month_summary_uncategorized_debt_payments_use_synthetic_category(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);

        $incomeCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Salary',
            'is_income' => true,
            'is_expense' => false,
        ]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 200.00,
            'balance' => 200.00,
            'is_pending_closeout' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'category_id' => $incomeCat->id,
            'type' => 'income',
            'amount' => 3000,
            'description' => 'Pay',
            'transaction_date' => '2026-09-01',
            'is_split' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'category_id' => null,
            'type' => 'expense',
            'amount' => 75,
            'description' => 'Uncategorized debt pay',
            'transaction_date' => '2026-09-10',
            'is_split' => false,
            'is_debt_payment' => true,
            'debt_id' => $debt->id,
            'is_closeout_initiated' => false,
        ]);

        $summary = $this->actingAs($debtor)->getJson('/month-summary?year=2026&month=9')->assertOk();

        $synthetic = collect($summary->json('category_totals'))
            ->firstWhere('category_name', 'Uncategorized Debt Payments');

        $this->assertNotNull($synthetic);
        $this->assertSame(-1, $synthetic['category_id']);
        $this->assertEqualsWithDelta(75.0, (float) $synthetic['total'], 0.001);
    }

    public function test_month_summary_category_transactions_include_viewer_rows_for_each_category_bucket(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $incomeCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Salary',
            'is_income' => true,
            'is_expense' => false,
        ]);

        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Dining',
            'is_expense' => true,
            'is_income' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'category_id' => $incomeCat->id,
            'type' => 'income',
            'amount' => 4200,
            'description' => 'Paycheck',
            'transaction_date' => '2026-10-02',
            'is_split' => false,
        ]);

        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-10-08',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 60],
                ['user_id' => $bob->id, 'share_percentage' => 40],
            ],
        ])->assertCreated();

        $summary = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=10')->assertOk();

        $incomeRows = collect($summary->json('category_transactions.income_'.$incomeCat->id));
        $expenseRows = collect($summary->json('category_transactions.expense_'.$expenseCat->id));

        $this->assertCount(1, $incomeRows);
        $this->assertEqualsWithDelta(4200.0, (float) $incomeRows->first()['amount'], 0.001);

        $this->assertCount(1, $expenseRows);
        $this->assertEqualsWithDelta(60.0, (float) $expenseRows->first()['amount'], 0.001);
        $this->assertTrue((bool) $expenseRows->first()['is_split']);
        $this->assertCount(2, $expenseRows->first()['split_breakdown']);
        $this->assertSame($alice->id, $expenseRows->first()['split_breakdown'][0]['user_id']);
        $this->assertEqualsWithDelta(60.0, (float) $expenseRows->first()['split_breakdown'][0]['amount'], 0.001);
        $this->assertSame($bob->id, $expenseRows->first()['split_breakdown'][1]['user_id']);
        $this->assertEqualsWithDelta(40.0, (float) $expenseRows->first()['split_breakdown'][1]['amount'], 0.001);
    }

    public function test_month_summary_category_transactions_include_synthetic_uncategorized_debt_payment_bucket(): void
    {
        $family = Family::factory()->create();
        $debtor = User::factory()->create(['family_id' => $family->id]);
        $creditor = User::factory()->create(['family_id' => $family->id]);

        $debt = Debt::factory()->create([
            'family_id' => $family->id,
            'debtor_id' => $debtor->id,
            'creditor_id' => $creditor->id,
            'amount' => 200.00,
            'balance' => 200.00,
            'is_pending_closeout' => false,
        ]);

        Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $debtor->id,
            'category_id' => null,
            'type' => 'expense',
            'amount' => 75,
            'description' => 'Uncategorized debt pay',
            'transaction_date' => '2026-11-10',
            'is_split' => false,
            'is_debt_payment' => true,
            'debt_id' => $debt->id,
            'is_closeout_initiated' => false,
        ]);

        $summary = $this->actingAs($debtor)->getJson('/month-summary?year=2026&month=11')->assertOk();

        $syntheticRows = collect($summary->json('category_transactions.expense_-1'));

        $this->assertCount(1, $syntheticRows);
        $this->assertEqualsWithDelta(75.0, (float) $syntheticRows->first()['amount'], 0.001);
        $this->assertSame('Uncategorized debt pay', $syntheticRows->first()['description']);
        $this->assertFalse((bool) $syntheticRows->first()['is_split']);
        $this->assertSame([], $syntheticRows->first()['split_breakdown']);
    }

    public function test_category_totals_solo_expense_query_excludes_closeout_initiated_rows(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 5, 14, 12, 0, 0));

        try {
            $family = Family::factory()->create();
            $user = User::factory()->create(['family_id' => $family->id]);

            $salaryCategory = Category::factory()->create([
                'family_id' => $family->id,
                'name' => 'Salary',
                'is_income' => true,
                'is_expense' => false,
            ]);

            $regularExpenseCategory = Category::factory()->create([
                'family_id' => $family->id,
                'name' => 'Weekly spend',
                'is_expense' => true,
                'is_income' => false,
            ]);

            $closeoutExpenseCategory = Category::factory()->create([
                'family_id' => $family->id,
                'name' => 'Closeout ledger',
                'is_expense' => true,
                'is_income' => false,
            ]);

            Transaction::query()->create([
                'family_id' => $family->id,
                'user_id' => $user->id,
                'category_id' => $salaryCategory->id,
                'type' => 'income',
                'amount' => 3000,
                'description' => 'Pay',
                'transaction_date' => '2028-05-02',
                'is_split' => false,
                'is_debt_payment' => false,
                'is_borrow' => false,
            ]);

            Transaction::query()->create([
                'family_id' => $family->id,
                'user_id' => $user->id,
                'category_id' => $regularExpenseCategory->id,
                'type' => 'expense',
                'amount' => 500,
                'description' => 'Groceries',
                'transaction_date' => '2028-05-06',
                'is_split' => false,
                'is_debt_payment' => false,
                'is_closeout_initiated' => false,
            ]);

            $fundA = Fund::factory()->create([
                'user_id' => $user->id,
                'family_id' => null,
                'balance' => 0,
            ]);

            FundRule::query()->create([
                'user_id' => $user->id,
                'fund_id' => $fundA->id,
                'name' => 'Emergency top-up',
                'order' => 1,
                'allocation_type' => 'fixed',
                'amount' => 300,
                'allocation_base' => 'gross_income',
                'is_active' => true,
                'destination_type' => 'fund',
                'destination_id' => $fundA->id,
                'destination_title' => null,
                'closeout_expense_category_id' => $closeoutExpenseCategory->id,
            ]);

            $assertExpenseBasis = function () use ($user): void {
                $response = $this->actingAs($user)->getJson('/month-summary?year=2028&month=5')->assertOk();
                $expenseSum = collect($response->json('category_totals'))
                    ->where('type', 'expense')
                    ->sum('total');
                $this->assertEqualsWithDelta(500.0, (float) $expenseSum, 0.01);
                $this->assertEqualsWithDelta(500.0, (float) $response->json('rule_preview.basis.total_expenses'), 0.01);
            };

            $assertExpenseBasis();

            $closeResponse = $this->actingAs($user)->postJson('/closeout/soft-close', [
                'year' => 2028,
                'month' => 5,
            ])->assertOk();
            $this->assertTrue($closeResponse->json('auto_hard_closed'));

            $this->assertDatabaseHas('transactions', [
                'user_id' => $user->id,
                'type' => 'expense',
                'amount' => 300,
                'category_id' => $closeoutExpenseCategory->id,
                'is_closeout_initiated' => true,
            ]);

            $assertExpenseBasis();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_income_category_totals_exclude_is_borrow_transactions(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 6, 12, 12, 0, 0));

        try {
            $family = Family::factory()->create();
            $user = User::factory()->create(['family_id' => $family->id]);

            $salaryCategory = Category::factory()->create([
                'family_id' => $family->id,
                'name' => 'Salary',
                'is_income' => true,
                'is_expense' => false,
            ]);

            Transaction::query()->create([
                'family_id' => $family->id,
                'user_id' => $user->id,
                'category_id' => $salaryCategory->id,
                'type' => 'income',
                'amount' => 4000,
                'description' => 'Paycheck',
                'transaction_date' => '2028-06-04',
                'is_split' => false,
                'is_debt_payment' => false,
                'is_borrow' => false,
            ]);

            $fund = Fund::factory()->create([
                'user_id' => $user->id,
                'family_id' => null,
                'balance' => 2000,
            ]);

            $this->actingAs($user)->postJson("/funds/{$fund->id}/borrow", [
                'amount' => 600,
                'description' => 'Short-term liquidity',
            ])->assertCreated();

            $summary = $this->actingAs($user)->getJson('/month-summary?year=2028&month=6')->assertOk();

            $incomeSum = collect($summary->json('category_totals'))
                ->where('type', 'income')
                ->sum('total');

            $this->assertEqualsWithDelta(4000.0, (float) $incomeSum, 0.01);
            $this->assertEqualsWithDelta(4000.0, (float) $summary->json('rule_preview.basis.gross_income'), 0.01);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_month_summary_member_balances_reflect_net_split_expenses_vs_each_member(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Dining',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-07-15',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 60],
                ['user_id' => $bob->id, 'share_percentage' => 40],
            ],
        ])->assertCreated();

        $aliceSummary = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $aliceBalances = $aliceSummary->json('member_balances');
        $this->assertCount(1, $aliceBalances);
        $this->assertSame($bob->id, $aliceBalances[0]['user_id']);
        $this->assertSame('they_owe_you', $aliceBalances[0]['direction']);
        $this->assertEqualsWithDelta(40.0, (float) $aliceBalances[0]['net_amount'], 0.02);
        $this->assertEqualsWithDelta(40.0, (float) $aliceBalances[0]['from_you_created_amount'], 0.02);
        $this->assertEqualsWithDelta(0.0, (float) $aliceBalances[0]['from_them_created_amount'], 0.02);
        $this->assertCount(1, $aliceBalances[0]['from_you_created_transactions']);
        $this->assertCount(0, $aliceBalances[0]['from_them_created_transactions']);
        $this->assertEqualsWithDelta(40.0, (float) $aliceBalances[0]['from_you_created_transactions'][0]['balance_amount'], 0.02);

        $bobSummary = $this->actingAs($bob)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $bobBalances = $bobSummary->json('member_balances');
        $this->assertCount(1, $bobBalances);
        $this->assertSame($alice->id, $bobBalances[0]['user_id']);
        $this->assertSame('you_owe_them', $bobBalances[0]['direction']);
        $this->assertEqualsWithDelta(40.0, (float) $bobBalances[0]['net_amount'], 0.02);
        $this->assertEqualsWithDelta(0.0, (float) $bobBalances[0]['from_you_created_amount'], 0.02);
        $this->assertEqualsWithDelta(40.0, (float) $bobBalances[0]['from_them_created_amount'], 0.02);
        $this->assertCount(0, $bobBalances[0]['from_you_created_transactions']);
        $this->assertCount(1, $bobBalances[0]['from_them_created_transactions']);
        $this->assertEqualsWithDelta(40.0, (float) $bobBalances[0]['from_them_created_transactions'][0]['balance_amount'], 0.02);
    }

    public function test_month_summary_member_balances_include_source_breakdown_for_both_creators(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Shared',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 120,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-07-02',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $bob->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        $this->actingAs($bob)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 80,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-07-06',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $bob->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        $aliceSummary = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=7')->assertOk();
        $balances = $aliceSummary->json('member_balances');

        $this->assertCount(1, $balances);
        $this->assertSame($bob->id, $balances[0]['user_id']);
        $this->assertEqualsWithDelta(60.0, (float) $balances[0]['from_you_created_amount'], 0.02);
        $this->assertEqualsWithDelta(40.0, (float) $balances[0]['from_them_created_amount'], 0.02);
        $this->assertEqualsWithDelta(20.0, (float) $balances[0]['net_amount'], 0.02);
        $this->assertSame('they_owe_you', $balances[0]['direction']);
        $this->assertCount(1, $balances[0]['from_you_created_transactions']);
        $this->assertCount(1, $balances[0]['from_them_created_transactions']);
    }

    public function test_month_summary_member_balances_omit_members_when_month_net_splits_to_zero(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Dining',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-07-05',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $bob->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        $this->actingAs($bob)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-07-20',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $bob->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        $aliceSummary = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=7')->assertOk();

        $this->assertSame([], $aliceSummary->json('member_balances'));
    }

    public function test_month_summary_member_balances_include_split_debt_payment_and_exclude_closeout_splits(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create(['family_id' => $family->id]);
        $bob = User::factory()->create(['family_id' => $family->id]);

        $expenseCat = Category::factory()->create([
            'family_id' => $family->id,
            'name' => 'Misc',
            'is_expense' => true,
            'is_income' => false,
        ]);

        $splitDebtPayment = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $alice->id,
            'category_id' => $expenseCat->id,
            'type' => 'expense',
            'amount' => 80,
            'description' => 'Fixture split debt payment',
            'transaction_date' => '2026-09-10',
            'is_split' => true,
            'is_debt_payment' => true,
            'is_closeout_initiated' => false,
        ]);

        TransactionSplit::query()->create([
            'transaction_id' => $splitDebtPayment->id,
            'user_id' => $alice->id,
            'share_percentage' => 50,
            'amount' => 40,
        ]);
        TransactionSplit::query()->create([
            'transaction_id' => $splitDebtPayment->id,
            'user_id' => $bob->id,
            'share_percentage' => 50,
            'amount' => 40,
        ]);

        $closeoutSplit = Transaction::query()->create([
            'family_id' => $family->id,
            'user_id' => $bob->id,
            'category_id' => $expenseCat->id,
            'type' => 'expense',
            'amount' => 40,
            'description' => 'Fixture closeout split',
            'transaction_date' => '2026-09-12',
            'is_split' => true,
            'is_debt_payment' => false,
            'is_closeout_initiated' => true,
        ]);

        TransactionSplit::query()->create([
            'transaction_id' => $closeoutSplit->id,
            'user_id' => $bob->id,
            'share_percentage' => 50,
            'amount' => 20,
        ]);
        TransactionSplit::query()->create([
            'transaction_id' => $closeoutSplit->id,
            'user_id' => $alice->id,
            'share_percentage' => 50,
            'amount' => 20,
        ]);

        $aliceSummaryOnlyDebtPayment = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=9')->assertOk();
        $balancesFromDebtPaymentOnly = $aliceSummaryOnlyDebtPayment->json('member_balances');
        $this->assertCount(1, $balancesFromDebtPaymentOnly);
        $this->assertSame($bob->id, $balancesFromDebtPaymentOnly[0]['user_id']);
        $this->assertSame('they_owe_you', $balancesFromDebtPaymentOnly[0]['direction']);
        $this->assertEqualsWithDelta(40.0, (float) $balancesFromDebtPaymentOnly[0]['net_amount'], 0.02);

        $this->actingAs($alice)->postJson('/transactions', [
            'type' => 'expense',
            'amount' => 60,
            'category_id' => $expenseCat->id,
            'transaction_date' => '2026-09-15',
            'is_split' => true,
            'split_data' => [
                ['user_id' => $alice->id, 'share_percentage' => 50],
                ['user_id' => $bob->id, 'share_percentage' => 50],
            ],
        ])->assertCreated();

        $aliceSummaryWithBillSplit = $this->actingAs($alice)->getJson('/month-summary?year=2026&month=9')->assertOk();
        $balances = $aliceSummaryWithBillSplit->json('member_balances');
        $this->assertCount(1, $balances);
        $this->assertSame($bob->id, $balances[0]['user_id']);
        $this->assertSame('they_owe_you', $balances[0]['direction']);
        $this->assertEqualsWithDelta(70.0, (float) $balances[0]['net_amount'], 0.02);
    }
}
