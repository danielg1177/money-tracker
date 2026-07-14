<?php

namespace App\Services;

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
     * Soft close means the user is done entering their own transactions.
     * It does not block other open family members from including them on splits
     * or from recording debt payments that create a mirrored row for them.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertTransactionPayloadOpen(User $owner, array $data): void
    {
        [$year, $month] = $this->yearMonthFromDate($data['transaction_date']);

        $this->assertUsersOpenForMonth((int) $owner->family_id, [(int) $owner->id], $year, $month);
    }

    /**
     * @param  array<string, mixed>|null  $nextData
     */
    public function assertTransactionMutationOpen(Transaction $transaction, ?array $nextData = null): void
    {
        [$year, $month] = $this->yearMonthFromDate($transaction->transaction_date);
        $this->assertUsersOpenForMonth(
            (int) $transaction->family_id,
            [(int) $transaction->user_id],
            $year,
            $month
        );

        if ($nextData !== null) {
            $transaction->loadMissing('user');
            $this->assertTransactionPayloadOpen($transaction->user, $nextData);
        }
    }

    public function assertDebtPaymentOpen(
        User $payer,
        string|DateTimeInterface|null $paymentDate = null,
    ): void {
        [$year, $month] = $this->yearMonthFromDate($paymentDate ?? today());

        // Soft-close only the payer (the person recording the payment). A soft-closed
        // creditor or split co-participant does not block the open payer.
        $this->assertUsersOpenForMonth((int) $payer->family_id, [(int) $payer->id], $year, $month);
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
}
