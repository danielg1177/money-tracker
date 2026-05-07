<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Debt;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
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

    public function test_month_summary_includes_debt_payments_as_synthetic_category_and_in_rule_preview_expenses(): void
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

        $debtPaymentsRow = collect($summary->json('category_totals'))
            ->firstWhere('category_name', 'Debt payments');

        $this->assertNotNull($debtPaymentsRow);
        $this->assertSame(-1, $debtPaymentsRow['category_id']);
        $this->assertEqualsWithDelta(150.0, (float) $debtPaymentsRow['total'], 0.001);

        $miscRow = collect($summary->json('category_totals'))
            ->where('type', 'expense')
            ->firstWhere('category_id', $expenseCat->id);

        $this->assertNotNull($miscRow);
        $this->assertEqualsWithDelta(800.0, (float) $miscRow['total'], 0.001);

        $this->assertEqualsWithDelta(950.0, (float) $summary->json('rule_preview.basis.total_expenses'), 0.001);
    }
}
