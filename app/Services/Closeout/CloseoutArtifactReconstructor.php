<?php

namespace App\Services\Closeout;

use App\Models\CloseoutTitleSaving;
use App\Models\Family;
use App\Models\FundMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

class CloseoutArtifactReconstructor
{
    public function __construct(
        private CloseoutRulePreviewBuilder $previewBuilder,
        private CloseoutTotals $totals,
    ) {}

    /**
     * Rebuild a family-wide classic snapshot from persisted closeout ledger rows.
     *
     * Does not read today’s FundRule percentages.
     *
     * @return array{mode: string, family: null, members: array<string, array<string, mixed>>, reconstructed: true}
     */
    public function reconstructForFamily(Family $family, int $year, int $month): array
    {
        $family->loadMissing('users');

        $userIds = $family->users->pluck('id')
            ->merge($this->artifactUserIds($family, $year, $month))
            ->unique()
            ->filter();

        $members = [];
        foreach (User::query()->whereIn('id', $userIds)->orderBy('id')->get() as $user) {
            $reconstructed = $this->reconstructForUser($user, $year, $month);
            $members[(string) $user->id] = $reconstructed['members'][(string) $user->id];
        }

        return [
            'mode' => CloseoutMode::Classic,
            'family' => null,
            'members' => $members,
            'reconstructed' => true,
        ];
    }

    /**
     * Rebuild a classic-shaped preview from persisted closeout ledger rows when no snapshot exists.
     *
     * @return array{mode: string, family: null, members: array<string, array<string, mixed>>}
     */
    public function reconstructForUser(User $user, int $year, int $month): array
    {
        $grossIncome = $this->previewBuilder->previewGrossIncome($user, $year, $month);
        $totalExpenses = $this->totals->expenseTotalTowardRemainingBasis($user, $year, $month);
        $expenseBasisExclusions = $this->previewBuilder->expenseBasisExclusions($user, $year, $month);

        $rules = [];
        $order = 1;
        $monthTag = sprintf('%04d-%02d', $year, $month);

        $movements = FundMovement::query()
            ->where('user_id', $user->id)
            ->where('type', 'closeout_allocation')
            ->where('description', 'like', '%('.$monthTag.')%')
            ->with('fund')
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $amount = round((float) $movement->amount, 2);
            $rules[] = $this->previewBuilder->formatRuleRow(
                ruleId: null,
                ruleName: $movement->description ?: 'Closeout fund allocation',
                order: $order++,
                allocationType: 'fixed',
                amount: $amount,
                allocationBase: 'remaining',
                destinationType: 'fund',
                destinationId: $movement->fund_id ? (int) $movement->fund_id : null,
                destinationTitle: null,
                projectedAmount: $amount,
                fundAdvanceOutstandingBefore: 0.0,
                netAfterAdvances: $amount,
                isActive: true,
            );
        }

        $debtPayments = Transaction::query()
            ->where('user_id', $user->id)
            ->where('is_closeout_initiated', true)
            ->where('is_debt_payment', true)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->with('debt')
            ->orderBy('id')
            ->get();

        foreach ($debtPayments as $transaction) {
            $amount = round((float) $transaction->amount, 2);
            $debt = $transaction->debt;
            $destinationName = $debt?->creditor_name ?? $debt?->creditor?->name ?? 'Debt';

            $rules[] = $this->previewBuilder->formatRuleRow(
                ruleId: null,
                ruleName: $transaction->description ?: "Debt Payment: {$destinationName}",
                order: $order++,
                allocationType: 'fixed',
                amount: $amount,
                allocationBase: 'remaining',
                destinationType: 'debt',
                destinationId: $transaction->debt_id ? (int) $transaction->debt_id : null,
                destinationTitle: null,
                projectedAmount: $amount,
                fundAdvanceOutstandingBefore: 0.0,
                netAfterAdvances: $amount,
                isActive: true,
            );
        }

        $titleSavings = CloseoutTitleSaving::query()
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('title')
            ->get();

        foreach ($titleSavings as $saving) {
            $amount = round((float) $saving->amount, 2);
            $rules[] = $this->previewBuilder->formatRuleRow(
                ruleId: $saving->rule_id ? (int) $saving->rule_id : null,
                ruleName: $saving->title,
                order: $order++,
                allocationType: 'fixed',
                amount: $amount,
                allocationBase: 'remaining',
                destinationType: 'title',
                destinationId: null,
                destinationTitle: $saving->title,
                projectedAmount: $amount,
                fundAdvanceOutstandingBefore: 0.0,
                netAfterAdvances: $amount,
                isActive: true,
            );
        }

        $preview = $this->previewBuilder->emptyUserPreview();
        $preview['basis'] = [
            'gross_income' => round($grossIncome, 2),
            'total_expenses' => round($totalExpenses, 2),
            'expense_basis_exclusions' => round($expenseBasisExclusions, 2),
            'non_necessity_expenses' => round($expenseBasisExclusions, 2),
            'gross_allocations_total' => 0.0,
            'remaining_after_expenses' => round($grossIncome - $totalExpenses, 2),
        ];
        $preview['expense_closeout_basis']['lines'] = [
            'This month was hard-closed before closeout settings were snapshotted.',
            'Amounts below are reconstructed from saved fund movements, debt payments, and title savings — not from today’s rules.',
            ...$this->previewBuilder->expenseCloseoutBasisLines(),
        ];
        $preview['rules'] = $rules;

        return [
            'mode' => CloseoutMode::Classic,
            'family' => null,
            'members' => [
                (string) $user->id => $preview,
            ],
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function artifactUserIds(Family $family, int $year, int $month): Collection
    {
        $monthTag = sprintf('%04d-%02d', $year, $month);

        $fromTransactions = Transaction::query()
            ->where('family_id', $family->id)
            ->where('is_closeout_initiated', true)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->pluck('user_id');

        $fromMovements = FundMovement::query()
            ->where('type', 'closeout_allocation')
            ->where('description', 'like', '%('.$monthTag.')%')
            ->whereIn('user_id', User::query()->where('family_id', $family->id)->select('id'))
            ->pluck('user_id');

        return $fromTransactions->merge($fromMovements);
    }
}
