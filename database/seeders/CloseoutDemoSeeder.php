<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryUserDefault;
use App\Models\CloseoutTitleSaving;
use App\Models\Debt;
use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use App\Models\Fund;
use App\Models\FundMovement;
use App\Models\FundRule;
use App\Models\MonthHardClose;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthCloseoutService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CloseoutDemoSeeder extends Seeder
{
    private const FAMILY_NAME = 'Closeout Demo';

    private const ALEX_EMAIL = 'alex@demo.test';

    private const JORDAN_EMAIL = 'jordan@demo.test';

    /**
     * @var array<string, Category>
     */
    private array $categories = [];

    /**
     * @var array<string, Fund>
     */
    private array $funds = [];

    public function run(): void
    {
        $this->destroyExistingDemo();

        $now = now();
        $openMonth = $now->copy()->startOfMonth();
        $pooledClosedMonth = $now->copy()->startOfMonth()->subMonth();
        $classicClosedMonth = $now->copy()->startOfMonth()->subMonths(2);

        $family = Family::query()->create([
            'name' => self::FAMILY_NAME,
            'description' => 'Local demo household for classic vs family-pooled closeout.',
            'closeout_mode' => 'classic',
        ]);

        $alex = User::query()->create([
            'name' => 'Alex Rivera',
            'email' => self::ALEX_EMAIL,
            'password' => Hash::make('password'),
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => true,
            'view_family_expenses' => true,
            'email_verified_at' => now(),
        ]);
        $jordan = User::query()->create([
            'name' => 'Jordan Rivera',
            'email' => self::JORDAN_EMAIL,
            'password' => Hash::make('password'),
            'family_id' => $family->id,
            'role' => 'member',
            'is_admin' => false,
            'view_family_expenses' => false,
            'email_verified_at' => now(),
        ]);

        $this->seedCategories($family, $alex, $jordan);
        $this->seedFundsAndRules($alex, $jordan);

        $transactions = app(TransactionService::class);
        $closeout = app(MonthCloseoutService::class);

        $this->seedClassicMonth($transactions, $alex, $jordan, $classicClosedMonth);
        $this->closeMonth($closeout, $family->fresh(), $alex->fresh(), $jordan->fresh(), $classicClosedMonth);

        $family->update(['closeout_mode' => 'family_pooled']);
        $this->seedFamilyCloseoutRules($family);

        $this->seedFamilyPooledMonth($transactions, $alex->fresh(), $jordan->fresh(), $pooledClosedMonth);
        $this->closeMonth($closeout, $family->fresh(), $alex->fresh(), $jordan->fresh(), $pooledClosedMonth);

        $this->seedOpenMonth($transactions, $alex->fresh(), $jordan->fresh(), $openMonth);

        $this->command?->newLine();
        $this->command?->info('Closeout demo household is ready.');
        $this->command?->info('Log in as alex@demo.test / password (admin + head of household, family expenses on).');
        $this->command?->info('Or jordan@demo.test / password (member).');
        $this->command?->info(sprintf(
            'Closed months: %s (classic snapshot) and %s (family pooled snapshot). Open month: %s.',
            $classicClosedMonth->format('F Y'),
            $pooledClosedMonth->format('F Y'),
            $openMonth->format('F Y'),
        ));
        $this->command?->info('Open Closeout Rules, then View month for the open month to preview family pooled closeout.');
    }

    private function destroyExistingDemo(): void
    {
        $familyIds = Family::query()
            ->where('name', self::FAMILY_NAME)
            ->pluck('id')
            ->merge(
                User::query()
                    ->whereIn('email', [self::ALEX_EMAIL, self::JORDAN_EMAIL])
                    ->pluck('family_id')
            )
            ->filter()
            ->unique();

        foreach ($familyIds as $familyId) {
            $userIds = User::query()->where('family_id', $familyId)->pluck('id');

            MonthHardClose::query()->where('family_id', $familyId)->delete();
            MonthSoftClose::query()->where('family_id', $familyId)->delete();
            CloseoutTitleSaving::query()->where('family_id', $familyId)->delete();
            FamilyCloseoutRule::query()->where('family_id', $familyId)->delete();
            FundRule::query()->whereIn('user_id', $userIds)->delete();
            FundMovement::query()->whereIn('user_id', $userIds)->delete();

            Transaction::query()->where('family_id', $familyId)->update([
                'mirror_transaction_id' => null,
                'debt_payment_income_id' => null,
                'debt_id' => null,
                'advance_fund_id' => null,
            ]);
            Transaction::query()->where('family_id', $familyId)->delete();
            Debt::query()->where('family_id', $familyId)->delete();
            CategoryUserDefault::query()->whereIn('user_id', $userIds)->delete();
            Fund::query()->where('family_id', $familyId)->orWhereIn('user_id', $userIds)->delete();
            Category::query()->where('family_id', $familyId)->delete();
            User::query()->where('family_id', $familyId)->delete();
            Family::query()->where('id', $familyId)->delete();
        }

        User::query()->whereIn('email', [self::ALEX_EMAIL, self::JORDAN_EMAIL])->delete();
    }

    private function seedCategories(Family $family, User $alex, User $jordan): void
    {
        $this->categories = [
            'paycheck' => $this->category($family, 'Paycheck', '💰', income: true),
            'rent' => $this->category($family, 'Rent', '🏠', income: false),
            'groceries' => $this->category($family, 'Groceries', '🛒', income: false),
            'utilities' => $this->category($family, 'Utilities', '💡', income: false),
            'dining' => $this->category($family, 'Dining', '🍕', income: false, necessity: false),
            'gas' => $this->category($family, 'Gas', '🚗', income: false),
            'medical' => $this->category($family, 'Medical', '💊', income: false),
            'fun' => $this->category($family, 'Fun', '🎉', income: false),
        ];

        $this->categories['rent']->update([
            'is_split_default' => true,
            'split_default' => [
                ['user_id' => $alex->id, 'share_percentage' => 70],
                ['user_id' => $jordan->id, 'share_percentage' => 30],
            ],
        ]);
    }

    private function seedFundsAndRules(User $alex, User $jordan): void
    {
        $this->funds = [
            'charity' => $this->fund($alex, 'Charity', $alex->family_id),
            'house' => $this->fund($alex, 'House savings', $alex->family_id),
            'vacation' => $this->fund($alex, 'Vacation', $alex->family_id),
            'alex_emergency' => $this->fund($alex, 'Alex emergency', null, 250),
            'alex_fun' => $this->fund($alex, 'Alex fun money', null, 80),
            'jordan_savings' => $this->fund($jordan, 'Jordan savings', null, 40),
        ];

        FundRule::query()->create([
            'user_id' => $alex->id,
            'fund_id' => $this->funds['alex_emergency']->id,
            'name' => 'Emergency 10% of gross',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'allocation_base' => 'gross_income',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $this->funds['alex_emergency']->id,
        ]);
        FundRule::query()->create([
            'user_id' => $alex->id,
            'fund_id' => $this->funds['alex_fun']->id,
            'name' => 'Fun money 50% of leftover',
            'order' => 2,
            'allocation_type' => 'percentage',
            'amount' => 50,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $this->funds['alex_fun']->id,
        ]);
        FundRule::query()->create([
            'user_id' => $alex->id,
            'fund_id' => null,
            'name' => 'Birthday cash',
            'order' => 3,
            'allocation_type' => 'percentage',
            'amount' => 20,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'title',
            'destination_title' => 'Jordan birthday',
        ]);
        FundRule::query()->create([
            'user_id' => $jordan->id,
            'fund_id' => $this->funds['jordan_savings']->id,
            'name' => 'Savings 80% of leftover',
            'order' => 1,
            'allocation_type' => 'percentage',
            'amount' => 80,
            'allocation_base' => 'remaining',
            'is_active' => true,
            'destination_type' => 'fund',
            'destination_id' => $this->funds['jordan_savings']->id,
        ]);

        CategoryUserDefault::query()->create([
            'category_id' => $this->categories['dining']->id,
            'user_id' => $alex->id,
            'advance_fund_id' => $this->funds['alex_fun']->id,
            'exclude_from_expense_basis_default' => true,
        ]);
    }

    private function seedFamilyCloseoutRules(Family $family): void
    {
        FamilyCloseoutRule::query()->create([
            'family_id' => $family->id,
            'name' => 'Charity 10%',
            'order' => 1,
            'is_active' => true,
            'stage' => 'surplus',
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => $this->funds['charity']->id,
        ]);
        FamilyCloseoutRule::query()->create([
            'family_id' => $family->id,
            'name' => 'House 20%',
            'order' => 2,
            'is_active' => true,
            'stage' => 'remaining_after_charity',
            'allocation_type' => 'percentage',
            'amount' => 20,
            'destination_type' => 'fund',
            'destination_id' => $this->funds['house']->id,
        ]);
        FamilyCloseoutRule::query()->create([
            'family_id' => $family->id,
            'name' => 'Vacation 30%',
            'order' => 3,
            'is_active' => true,
            'stage' => 'remaining_after_charity',
            'allocation_type' => 'percentage',
            'amount' => 30,
            'destination_type' => 'fund',
            'destination_id' => $this->funds['vacation']->id,
        ]);
    }

    private function seedClassicMonth(TransactionService $transactions, User $alex, User $jordan, Carbon $month): void
    {
        $this->income($transactions, $alex, 4200, $this->on($month, 1), 'Alex paycheck');
        $this->income($transactions, $jordan, 3100, $this->on($month, 1), 'Jordan paycheck');
        $this->splitExpense($transactions, $alex, $jordan, 1800, 60, 40, $this->on($month, 3), 'Rent', $this->categories['rent']->id);
        $this->expense($transactions, $alex, 240, $this->on($month, 8), 'Groceries', $this->categories['groceries']->id);
        $this->expense($transactions, $jordan, 95, $this->on($month, 12), 'Pharmacy', $this->categories['medical']->id);
        $this->expense(
            $transactions,
            $alex,
            65,
            $this->on($month, 18),
            'Takeout',
            $this->categories['dining']->id,
            $this->funds['alex_fun']->id,
            true,
        );
    }

    private function seedFamilyPooledMonth(TransactionService $transactions, User $alex, User $jordan, Carbon $month): void
    {
        $this->income($transactions, $alex, 10000, $this->on($month, 1), 'Alex paycheck');
        $this->income($transactions, $jordan, 5000, $this->on($month, 2), 'Jordan paycheck');
        $this->splitExpense($transactions, $alex, $jordan, 10000, 70, 30, $this->on($month, 5), 'Rent', $this->categories['rent']->id);
        $this->splitExpense($transactions, $jordan, $alex, 220, 50, 50, $this->on($month, 9), 'Groceries', $this->categories['groceries']->id);
        $this->expense($transactions, $alex, 140, $this->on($month, 11), 'Electric', $this->categories['utilities']->id);
        $this->expense(
            $transactions,
            $alex,
            700,
            $this->on($month, 14),
            'Concert tickets',
            $this->categories['fun']->id,
            $this->funds['alex_fun']->id,
            true,
        );
    }

    private function seedOpenMonth(TransactionService $transactions, User $alex, User $jordan, Carbon $month): void
    {
        $this->income($transactions, $alex, 4800, $this->on($month, 1), 'Alex paycheck');
        $this->income($transactions, $jordan, 3600, $this->on($month, 2), 'Jordan paycheck');
        $this->income($transactions, $alex, 150, $this->on($month, 6), 'Side gig');

        $this->splitExpense($transactions, $alex, $jordan, 2100, 70, 30, $this->on($month, 3), 'Rent', $this->categories['rent']->id);
        $this->splitExpense($transactions, $alex, $jordan, 186.42, 50, 50, $this->on($month, 7), 'Costco', $this->categories['groceries']->id);
        $this->splitExpense($transactions, $jordan, $alex, 94.18, 55, 45, $this->on($month, 15), 'Trader Joe’s', $this->categories['groceries']->id);

        $this->expense($transactions, $alex, 128.40, $this->on($month, 4), 'Electric + internet', $this->categories['utilities']->id);
        $this->expense($transactions, $jordan, 54.20, $this->on($month, 8), 'Gas', $this->categories['gas']->id);
        $this->expense($transactions, $alex, 32.75, $this->on($month, 10), 'Pharmacy copay', $this->categories['medical']->id);
        $this->expense($transactions, $jordan, 19.99, $this->on($month, 12), 'Streaming', $this->categories['fun']->id);
        $this->expense(
            $transactions,
            $alex,
            86.50,
            $this->on($month, 13),
            'Date night',
            $this->categories['dining']->id,
            $this->funds['alex_fun']->id,
            true,
        );
        $this->expense($transactions, $alex, 41.12, $this->on($month, 16), 'Weeknight groceries', $this->categories['groceries']->id);
        $this->expense($transactions, $jordan, 27.00, $this->on($month, 18), 'Coffee with friends', $this->categories['dining']->id);
        $this->expense($transactions, $alex, 60.00, $this->on($month, 20), 'Car wash + gas', $this->categories['gas']->id);

        $debt = Debt::query()->create([
            'family_id' => $alex->family_id,
            'debtor_id' => $alex->id,
            'creditor_id' => $jordan->id,
            'amount' => 200,
            'balance' => 200,
            'description' => 'Concert tickets Alex owes Jordan',
            'is_pending_closeout' => false,
            'is_family_debt' => false,
        ]);

        $transactions->createTransaction([
            'type' => 'expense',
            'amount' => 75,
            'description' => 'Partial ticket repayment',
            'transaction_date' => $this->on($month, 17),
            'category_id' => $this->categories['fun']->id,
            'is_split' => false,
            'debt_id' => $debt->id,
        ], $alex);
    }

    private function closeMonth(
        MonthCloseoutService $closeout,
        Family $family,
        User $alex,
        User $jordan,
        Carbon $month,
    ): void {
        $year = (int) $month->year;
        $monthNumber = (int) $month->month;

        $closeout->softClose($alex, $year, $monthNumber);
        $closeout->softClose($jordan, $year, $monthNumber);
        $closeout->hardClose($family, $alex, $year, $monthNumber);
    }

    private function income(TransactionService $transactions, User $user, float $amount, string $date, string $description): void
    {
        $transactions->createTransaction([
            'type' => 'income',
            'amount' => $amount,
            'description' => $description,
            'transaction_date' => $date,
            'category_id' => $this->categories['paycheck']->id,
            'is_split' => false,
        ], $user);
    }

    private function expense(
        TransactionService $transactions,
        User $user,
        float $amount,
        string $date,
        string $description,
        int $categoryId,
        ?int $advanceFundId = null,
        bool $nonNecessity = false,
    ): void {
        $transactions->createTransaction([
            'type' => 'expense',
            'amount' => $amount,
            'description' => $description,
            'transaction_date' => $date,
            'category_id' => $categoryId,
            'is_split' => false,
            'advance_fund_id' => $advanceFundId,
            'exclude_from_expense_basis' => $nonNecessity,
            'is_necessity' => ! $nonNecessity,
        ], $user);
    }

    private function splitExpense(
        TransactionService $transactions,
        User $payer,
        User $other,
        float $amount,
        float $payerPercent,
        float $otherPercent,
        string $date,
        string $description,
        int $categoryId,
    ): void {
        $transactions->createTransaction([
            'type' => 'expense',
            'amount' => $amount,
            'description' => $description,
            'transaction_date' => $date,
            'category_id' => $categoryId,
            'is_split' => true,
            'split_data' => [
                ['user_id' => $payer->id, 'share_percentage' => $payerPercent],
                ['user_id' => $other->id, 'share_percentage' => $otherPercent],
            ],
        ], $payer);
    }

    private function category(Family $family, string $name, string $icon, bool $income, bool $necessity = true): Category
    {
        return Category::query()->create([
            'family_id' => $family->id,
            'name' => $name,
            'icon' => $icon,
            'is_income' => $income,
            'is_expense' => ! $income,
            'is_split_default' => false,
            'split_default' => null,
            'is_necessity_default' => $income ? true : $necessity,
        ]);
    }

    private function fund(User $owner, string $name, ?int $familyId, float $balance = 0): Fund
    {
        return Fund::query()->create([
            'user_id' => $owner->id,
            'family_id' => $familyId,
            'name' => $name,
            'description' => null,
            'balance' => $balance,
        ]);
    }

    private function on(Carbon $month, int $day): string
    {
        $date = $month->copy()->day(min($day, $month->daysInMonth))->startOfDay();

        if ($month->isSameMonth(now()) && $date->gt(now())) {
            return now()->toDateString();
        }

        return $date->toDateString();
    }
}
