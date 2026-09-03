<?php

namespace App\Services\Closeout;

use App\Models\Family;
use App\Models\User;

interface CloseoutEngine
{
    /**
     * Dry-run closeout math for every family member (and family stages when pooled).
     *
     * @return array{mode: string, family: ?array<string, mixed>, members: array<string, array<string, mixed>>}
     */
    public function preview(Family $family, int $year, int $month): array;

    /**
     * Persist closeout allocations. Fund advances, split-debt consolidation, and interest stay in MonthCloseoutService.
     */
    public function apply(Family $family, User $actingUser, int $year, int $month): void;
}
