<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlaidMerchantRuleCategorySync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plaid:sync-merchant-rules-from-categories {--user= : Sync only this user ID}')]
#[Description('Copy current category defaults onto Plaid merchant rules, pending import suggestions, and unreviewed auto-created transactions.')]
class SyncPlaidMerchantRulesFromCategoriesCommand extends Command
{
    public function handle(PlaidMerchantRuleCategorySync $sync): int
    {
        $userOption = $this->option('user');
        if ($userOption !== null && $userOption !== '') {
            $user = User::query()->find((int) $userOption);
            if ($user === null) {
                $this->error("User id {$userOption} not found.");

                return self::FAILURE;
            }

            $this->line($this->formatTotals($sync->syncUser($user)));

            return self::SUCCESS;
        }

        $totals = $sync->emptyTotals();
        User::query()
            ->whereNotNull('family_id')
            ->orderBy('id')
            ->each(function (User $user) use ($sync, &$totals): void {
                $totals = $sync->addTotals($totals, $sync->syncUser($user));
            });

        $this->line($this->formatTotals($totals));

        return self::SUCCESS;
    }

    /**
     * @param  array{merchant_rules: int, pending_imports: int, auto_created_transactions: int}  $totals
     */
    private function formatTotals(array $totals): string
    {
        return sprintf(
            'Updated %d merchant rules, %d pending imports, %d auto-created transactions',
            $totals['merchant_rules'],
            $totals['pending_imports'],
            $totals['auto_created_transactions'],
        );
    }
}
