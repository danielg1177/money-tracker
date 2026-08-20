<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Family;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyExpenseViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_index_family_view_includes_other_members_solo_expenses_once(): void
    {
        $family = Family::factory()->create();
        $user1 = User::factory()->create(['family_id' => $family->id]);
        $user2 = User::factory()->create(['family_id' => $family->id]);
        $category = Category::factory()->create(['family_id' => $family->id]);

        $this->actingAs($user2)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 25.00,
            'description' => 'Solo expense',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => false,
        ])->assertStatus(201);

        $soloOtherMemberId = Transaction::query()->latest('id')->value('id');

        $this->actingAs($user2)->postJson('/transactions', [
            'category_id' => $category->id,
            'amount' => 100.00,
            'description' => 'Split expense',
            'type' => 'expense',
            'transaction_date' => now()->toDateString(),
            'is_split' => true,
            'split_data' => [
                ['user_id' => $user1->id, 'share_percentage' => 50],
                ['user_id' => $user2->id, 'share_percentage' => 50],
            ],
        ])->assertStatus(201);

        $splitSharedId = Transaction::query()->latest('id')->value('id');

        $personalIds = collect($this->actingAs($user1)->getJson('/transactions')->json())->pluck('id');
        $this->assertNotContains($soloOtherMemberId, $personalIds->all());
        $this->assertContains($splitSharedId, $personalIds->all());

        $familyIds = collect($this->actingAs($user1)->getJson('/transactions?view=family')->json())->pluck('id');
        $this->assertContains($soloOtherMemberId, $familyIds->all());
        $this->assertContains($splitSharedId, $familyIds->all());
        $this->assertSame(1, $familyIds->filter(fn ($id) => (int) $id === (int) $splitSharedId)->count());
    }

    public function test_month_summary_omits_family_category_totals_when_preference_is_off(): void
    {
        $family = Family::factory()->create();
        $user = User::factory()->create([
            'family_id' => $family->id,
            'view_family_expenses' => false,
        ]);

        $this->actingAs($user)->getJson('/month-summary?year=2026&month=7')
            ->assertOk()
            ->assertJsonMissingPath('family_category_totals')
            ->assertJsonMissingPath('family_category_transactions');
    }

    public function test_month_summary_family_category_totals_count_splits_once_and_keep_viewer_totals(): void
    {
        $family = Family::factory()->create();
        $alice = User::factory()->create([
            'family_id' => $family->id,
            'view_family_expenses' => true,
        ]);
        $bob = User::factory()->create([
            'family_id' => $family->id,
            'view_family_expenses' => true,
        ]);

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
            'amount' => 2000,
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

        $aliceExpenseTotal = collect($aliceSummary->json('category_totals'))
            ->where('type', 'expense')
            ->sum('total');
        $aliceIncomeTotal = collect($aliceSummary->json('category_totals'))
            ->where('type', 'income')
            ->sum('total');

        $this->assertEqualsWithDelta(60.0, $aliceExpenseTotal, 0.001);
        $this->assertEqualsWithDelta(2000.0, $aliceIncomeTotal, 0.001);

        $familyExpenseTotal = collect($aliceSummary->json('family_category_totals'))
            ->where('type', 'expense')
            ->sum('total');
        $familyIncomeTotal = collect($aliceSummary->json('family_category_totals'))
            ->where('type', 'income')
            ->sum('total');

        $this->assertEqualsWithDelta(180.0, $familyExpenseTotal, 0.001);
        $this->assertEqualsWithDelta(2000.0, $familyIncomeTotal, 0.001);

        $groceryRows = $aliceSummary->json('family_category_transactions.expense_'.$expenseCat->id);
        $this->assertIsArray($groceryRows);
        $this->assertCount(2, $groceryRows);

        $splitRow = collect($groceryRows)->firstWhere('is_split', true);
        $this->assertNotNull($splitRow);
        $this->assertEqualsWithDelta(100.0, (float) $splitRow['amount'], 0.001);
        $this->assertSame($alice->id, $splitRow['user_id']);
    }
}
