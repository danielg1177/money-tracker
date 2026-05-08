<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\MonthHardClose;
use App\Models\MonthSoftClose;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;

class ClosedMonthGuard
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function assertTransactionPayloadOpen(User $owner, array $data): void
    {
        [$year, $month] = $this->yearMonthFromDate($data['transaction_date']);

        $affectedUserIds = [(int) $owner->id];

        if (! empty($data['is_split']) && is_array($data['split_data'] ?? null)) {
            foreach ($data['split_data'] as $split) {
                if (isset($split['user_id'])) {
                    $affectedUserIds[] = (int) $split['user_id'];
                }
            }
        }

        if (($data['type'] ?? null) === 'expense' && ! empty($data['debt_id'])) {
            $debt = Debt::query()
                ->where('family_id', $owner->family_id)
                ->find($data['debt_id']);

            if ($debt?->creditor_id) {
                $affectedUserIds[] = (int) $debt->creditor_id;
            }
        }

        $this->assertUsersOpenForMonth((int) $owner->family_id, $affectedUserIds, $year, $month);
    }

    /**
     * @param  array<string, mixed>|null  $nextData
     */
    public function assertTransactionMutationOpen(Transaction $transaction, ?array $nextData = null): void
    {
        [$year, $month] = $this->yearMonthFromDate($transaction->transaction_date);
        $this->assertUsersOpenForMonth(
            (int) $transaction->family_id,
            $this->affectedUserIdsForTransaction($transaction),
            $year,
            $month
        );

        if ($nextData !== null) {
            $transaction->loadMissing('user');
            $this->assertTransactionPayloadOpen($transaction->user, $nextData);
        }
    }

    public function assertDebtPaymentOpen(
        Debt $debt,
        User $payer,
        string|DateTimeInterface|null $paymentDate = null,
        ?int $splitWithUserId = null,
    ): void {
        [$year, $month] = $this->yearMonthFromDate($paymentDate ?? today());

        $affectedUserIds = [(int) $payer->id];
        if ($splitWithUserId !== null) {
            $affectedUserIds[] = $splitWithUserId;
        }
        if ($debt->creditor_id !== null) {
            $affectedUserIds[] = (int) $debt->creditor_id;
        }

        $this->assertUsersOpenForMonth((int) $payer->family_id, $affectedUserIds, $year, $month);
    }

    public function assertUserDateOpen(User $user, string|DateTimeInterface|null $date = null): void
    {
        [$year, $month] = $this->yearMonthFromDate($date ?? today());

        $this->assertUsersOpenForMonth((int) $user->family_id, [(int) $user->id], $year, $month);
    }

    /**
     * @param  iterable<int>  $userIds
     */
    public function assertUsersOpenForMonth(int $familyId, iterable $userIds, int $year, int $month): void
    {
        if ($familyId <= 0) {
            return;
        }

        if (MonthHardClose::query()
            ->where('family_id', $familyId)
            ->where('year', $year)
            ->where('month', $month)
            ->exists()) {
            throw new InvalidArgumentException('This month is hard-closed and cannot be changed.');
        }

        $normalizedUserIds = collect($userIds)
            ->map(fn (int $userId): int => (int) $userId)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->unique()
            ->values();

        if ($normalizedUserIds->isEmpty()) {
            return;
        }

        if (MonthSoftClose::query()
            ->where('family_id', $familyId)
            ->whereIn('user_id', $normalizedUserIds)
            ->where('year', $year)
            ->where('month', $month)
            ->exists()) {
            throw new InvalidArgumentException('This month is soft-closed for an affected user and cannot be changed.');
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function yearMonthFromDate(string|DateTimeInterface $date): array
    {
        $carbon = $date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);

        return [(int) $carbon->year, (int) $carbon->month];
    }

    /**
     * @return array<int>
     */
    private function affectedUserIdsForTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['splits', 'mirrorTransaction']);

        $userIds = [(int) $transaction->user_id];

        foreach ($transaction->splits as $split) {
            $userIds[] = (int) $split->user_id;
        }

        if ($transaction->mirrorTransaction?->user_id) {
            $userIds[] = (int) $transaction->mirrorTransaction->user_id;
        }

        $reciprocalMirror = Transaction::query()
            ->where('mirror_transaction_id', $transaction->id)
            ->first();

        if ($reciprocalMirror?->user_id) {
            $userIds[] = (int) $reciprocalMirror->user_id;
        }

        return array_values(array_unique($userIds));
    }
}
