<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\MonthHardClose;
use App\Models\User;
use Carbon\Carbon;

class CloseoutShortfallCarry
{
    public function broughtForwardForFamily(Family $family, int $year, int $month): float
    {
        $shortfall = $this->previousShortfall($family, $year, $month);
        if ($shortfall === null) {
            return 0.0;
        }

        if (($shortfall['mode'] ?? null) === CloseoutMode::FamilyPooled) {
            return round((float) ($shortfall['amount'] ?? 0), 2);
        }

        return round(array_sum($this->memberAmounts($shortfall)), 2);
    }

    public function broughtForwardForUser(User $user, int $year, int $month): float
    {
        if (! $user->family_id) {
            return 0.0;
        }

        $family = $user->relationLoaded('family') ? $user->family : $user->family()->first();
        if (! $family) {
            return 0.0;
        }

        $shortfall = $this->previousShortfall($family, $year, $month);
        if ($shortfall === null) {
            return 0.0;
        }

        if (($shortfall['mode'] ?? null) === CloseoutMode::FamilyPooled) {
            $family->loadMissing('users');
            $memberCount = max(1, $family->users->count());

            return round(((float) ($shortfall['amount'] ?? 0)) / $memberCount, 2);
        }

        return round((float) ($this->memberAmounts($shortfall)[(string) $user->id] ?? 0), 2);
    }

    /**
     * @param  array<string, float>  $memberAmounts
     * @return array{mode: string, amount: float, brought_forward: float, members: array<string, float>}
     */
    public function snapshot(string $mode, float $amount, float $broughtForward, array $memberAmounts): array
    {
        return [
            'mode' => $mode,
            'amount' => round(max(0.0, $amount), 2),
            'brought_forward' => round(max(0.0, $broughtForward), 2),
            'members' => $memberAmounts,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previousShortfall(Family $family, int $year, int $month): ?array
    {
        $previous = Carbon::create($year, $month, 1)->subMonth();
        $hardClose = MonthHardClose::query()
            ->where('family_id', $family->id)
            ->where('year', $previous->year)
            ->where('month', $previous->month)
            ->first();

        $shortfall = $hardClose?->results_snapshot['shortfall'] ?? null;

        return is_array($shortfall) ? $shortfall : null;
    }

    /**
     * @param  array<string, mixed>  $shortfall
     * @return array<string, float>
     */
    private function memberAmounts(array $shortfall): array
    {
        $members = $shortfall['members'] ?? [];
        if (! is_array($members)) {
            return [];
        }

        $amounts = [];
        foreach ($members as $userId => $amount) {
            $amounts[(string) $userId] = round((float) $amount, 2);
        }

        return $amounts;
    }
}
