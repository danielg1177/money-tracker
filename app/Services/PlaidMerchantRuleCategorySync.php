<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryUserDefault;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Closeout\CloseoutMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PlaidMerchantRuleCategorySync
{
    public function __construct(private ClosedMonthGuard $closedMonthGuard) {}

    /**
     * @return array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}
     */
    public function emptyTotals(): array
    {
        return [
            'merchant_rules' => 0,
            'pending_imports' => 0,
            'auto_created_transactions' => 0,
        ];
    }

    /**
     * @param  array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}  $left
     * @param  array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}  $right
     * @return array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}
     */
    public function addTotals(array $left, array $right): array
    {
        return [
            'merchant_rules' => $left['merchant_rules'] + $right['merchant_rules'],
            'pending_imports' => $left['pending_imports'] + $right['pending_imports'],
            'auto_created_transactions' => $left['auto_created_transactions'] + $right['auto_created_transactions'],
        ];
    }

    /**
     * Copy this user's expense-category defaults onto their merchant rules,
     * still-open pending-import suggestions, and unreviewed auto-created ledger rows.
     *
     * @return array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}
     */
    public function syncUser(User $user): array
    {
        $totals = $this->emptyTotals();

        if ($user->family_id === null) {
            return $totals;
        }

        $categories = Category::query()
            ->where('family_id', $user->family_id)
            ->where('is_expense', true)
            ->get();

        foreach ($categories as $category) {
            $totals = $this->addTotals($totals, $this->syncUserCategory($user, $category));
        }

        return $totals;
    }

    /**
     * Copy family necessity onto every member's merchant learning for this category.
     * Personal advance / remaining-exclusion are applied only for $actor (the member who just saved).
     *
     * @return array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}
     */
    public function syncFamilyCategory(Category $category, User $actor): array
    {
        $totals = $this->emptyTotals();
        $category->loadMissing('family.users');

        foreach ($category->family?->users ?? [] as $member) {
            $totals = $this->addTotals($totals, $this->syncUserCategory(
                $member,
                $category,
                includePersonalDefaults: (int) $member->id === (int) $actor->id,
            ));
        }

        return $totals;
    }

    /**
     * @return array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}
     */
    public function syncUserCategory(User $user, Category $category, bool $includePersonalDefaults = true): array
    {
        if (! $category->is_expense) {
            return $this->emptyTotals();
        }

        return DB::transaction(function () use ($user, $category, $includePersonalDefaults): array {
            $settings = $this->settingsFromDefaults(
                $category,
                CategoryUserDefault::query()
                    ->where('user_id', $user->id)
                    ->where('category_id', $category->id)
                    ->first()
            );

            $merchantRules = 0;
            $rules = PlaidMerchantRule::query()
                ->where('user_id', $user->id)
                ->where('category_id', $category->id)
                ->get();

            foreach ($rules as $rule) {
                $rule->forceFill($this->merchantRuleFill($settings, $includePersonalDefaults))->save();
                $merchantRules++;
            }

            $pendingImports = 0;
            $imports = PlaidPendingImport::query()
                ->where('user_id', $user->id)
                ->where('suggested_category_id', $category->id)
                ->whereIn('status', ['pending', 'auto_created'])
                ->whereNull('reviewed_at')
                ->get();

            foreach ($imports as $import) {
                $import->forceFill($this->pendingImportFill($settings, $includePersonalDefaults))->save();
                $pendingImports++;
            }

            $autoCreatedTransactions = 0;
            $transactions = Transaction::query()
                ->where('user_id', $user->id)
                ->where('category_id', $category->id)
                ->where('type', 'expense')
                ->whereHas('plaidPendingImport', function ($query): void {
                    $query->where('status', 'auto_created')->whereNull('reviewed_at');
                })
                ->get();

            foreach ($transactions as $transaction) {
                if (! $this->ledgerMonthIsOpen($transaction)) {
                    continue;
                }

                $transaction->forceFill(
                    $this->autoCreatedTransactionFill($user, $transaction, $settings, $includePersonalDefaults)
                )->save();
                $autoCreatedTransactions++;
            }

            return [
                'merchant_rules' => $merchantRules,
                'pending_imports' => $pendingImports,
                'auto_created_transactions' => $autoCreatedTransactions,
            ];
        });
    }

    /**
     * @return array{advance_fund_id: int|null, fund_id: int|null, exclude_from_expense_basis: bool, is_necessity: bool}
     */
    private function settingsFromDefaults(Category $category, ?CategoryUserDefault $defaults): array
    {
        $advanceFundId = $defaults?->advance_fund_id;
        $advanceFundId = $advanceFundId !== null && (int) $advanceFundId !== 0
            ? (int) $advanceFundId
            : null;
        $excludeFromExpenseBasis = $advanceFundId !== null
            && (bool) ($defaults?->exclude_from_expense_basis_default ?? false);

        return [
            'advance_fund_id' => $advanceFundId,
            'fund_id' => $advanceFundId,
            'exclude_from_expense_basis' => $excludeFromExpenseBasis,
            'is_necessity' => (bool) $category->is_necessity_default,
        ];
    }

    /**
     * @param  array{advance_fund_id: int|null, fund_id: int|null, exclude_from_expense_basis: bool, is_necessity: bool}  $settings
     * @return array<string, mixed>
     */
    private function merchantRuleFill(array $settings, bool $includePersonalDefaults): array
    {
        $fill = ['is_necessity' => $settings['is_necessity']];
        if ($includePersonalDefaults) {
            $fill['advance_fund_id'] = $settings['advance_fund_id'];
            $fill['fund_id'] = $settings['fund_id'];
            $fill['exclude_from_expense_basis'] = $settings['exclude_from_expense_basis'];
        }

        return $fill;
    }

    /**
     * @param  array{advance_fund_id: int|null, fund_id: int|null, exclude_from_expense_basis: bool, is_necessity: bool}  $settings
     * @return array<string, mixed>
     */
    private function pendingImportFill(array $settings, bool $includePersonalDefaults): array
    {
        $fill = ['suggested_is_necessity' => $settings['is_necessity']];
        if ($includePersonalDefaults) {
            $fill['suggested_advance_fund_id'] = $settings['advance_fund_id'];
            $fill['suggested_fund_id'] = $settings['fund_id'];
            $fill['suggested_exclude_from_expense_basis'] = $settings['exclude_from_expense_basis'];
        }

        return $fill;
    }

    /**
     * @param  array{advance_fund_id: int|null, fund_id: int|null, exclude_from_expense_basis: bool, is_necessity: bool}  $settings
     * @return array<string, mixed>
     */
    private function autoCreatedTransactionFill(
        User $user,
        Transaction $transaction,
        array $settings,
        bool $includePersonalDefaults,
    ): array {
        $fill = ['is_necessity' => $settings['is_necessity']];
        if (! $includePersonalDefaults) {
            return $fill;
        }

        $excludeFromExpenseBasis = $settings['exclude_from_expense_basis'];
        if ($transaction->is_split || CloseoutMode::isFamilyPooled($user->closeout_mode)) {
            $excludeFromExpenseBasis = false;
        }

        $fill['advance_fund_id'] = $settings['advance_fund_id'];
        $fill['exclude_from_expense_basis'] = $excludeFromExpenseBasis;

        return $fill;
    }

    private function ledgerMonthIsOpen(Transaction $transaction): bool
    {
        try {
            $this->closedMonthGuard->assertTransactionMutationOpen($transaction);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
