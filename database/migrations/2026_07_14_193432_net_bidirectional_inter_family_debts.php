<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair for bidirectional open in-family debts caused by closeout /
 * overpayment creating a reverse-direction debt without netting the pair.
 *
 * For each family member pair, confirmed running debts (non-pending, no
 * transaction_id, personal / is_family_debt=false) with balance > 0 are netted
 * into a single open direction. Zero-balance history rows are left in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        $openDebts = DB::table('debts')
            ->where('is_pending_closeout', false)
            ->where('is_family_debt', false)
            ->whereNull('transaction_id')
            ->whereNotNull('creditor_id')
            ->where('balance', '>', 0)
            ->orderBy('id')
            ->get();

        /** @var Collection<string, Collection<int, object>> $groups */
        $groups = $openDebts->groupBy(function (object $debt): string {
            $lowId = min((int) $debt->debtor_id, (int) $debt->creditor_id);
            $highId = max((int) $debt->debtor_id, (int) $debt->creditor_id);

            return ((int) $debt->family_id).':'.$lowId.':'.$highId;
        });

        foreach ($groups as $key => $pairDebts) {
            if ($pairDebts->count() < 2) {
                continue;
            }

            [$familyId, $lowId, $highId] = array_map('intval', explode(':', $key));

            $lowToHigh = $pairDebts
                ->filter(fn (object $debt): bool => (int) $debt->debtor_id === $lowId
                    && (int) $debt->creditor_id === $highId)
                ->values();
            $highToLow = $pairDebts
                ->filter(fn (object $debt): bool => (int) $debt->debtor_id === $highId
                    && (int) $debt->creditor_id === $lowId)
                ->values();

            $totalLowToHigh = round((float) $lowToHigh->sum(fn (object $debt): float => (float) $debt->balance), 2);
            $totalHighToLow = round((float) $highToLow->sum(fn (object $debt): float => (float) $debt->balance), 2);
            $net = round($totalLowToHigh - $totalHighToLow, 2);

            $hasBothDirections = $lowToHigh->isNotEmpty() && $highToLow->isNotEmpty();
            $hasDuplicateDirection = $lowToHigh->count() > 1 || $highToLow->count() > 1;

            if (! $hasBothDirections && ! $hasDuplicateDirection) {
                continue;
            }

            DB::transaction(function () use (
                $pairDebts,
                $lowToHigh,
                $highToLow,
                $net,
                $familyId,
                $lowId,
                $highId,
            ): void {
                foreach ($pairDebts as $debt) {
                    DB::table('debts')->where('id', $debt->id)->update([
                        'balance' => 0,
                        'updated_at' => now(),
                    ]);
                }

                if (abs($net) < 0.01) {
                    return;
                }

                if ($net > 0) {
                    $this->restoreOpenBalance($lowToHigh, $familyId, $lowId, $highId, $net);
                } else {
                    $this->restoreOpenBalance($highToLow, $familyId, $highId, $lowId, abs($net));
                }
            });
        }
    }

    public function down(): void
    {
        // Irreversible data repair.
    }

    /**
     * @param  Collection<int, object>  $directionDebts
     */
    private function restoreOpenBalance(
        Collection $directionDebts,
        int $familyId,
        int $debtorId,
        int $creditorId,
        float $netAmount,
    ): void {
        $survivor = $directionDebts->sortBy('id')->first();

        if ($survivor) {
            $amount = max((float) $survivor->amount, $netAmount);
            DB::table('debts')->where('id', $survivor->id)->update([
                'amount' => $amount,
                'balance' => $netAmount,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('debts')->insert([
            'family_id' => $familyId,
            'debtor_id' => $debtorId,
            'creditor_id' => $creditorId,
            'fund_id' => null,
            'transaction_id' => null,
            'amount' => $netAmount,
            'balance' => $netAmount,
            'description' => 'Netted inter-family debt (data repair)',
            'contributions' => null,
            'is_pending_closeout' => false,
            'is_family_debt' => false,
            'creditor_name' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
