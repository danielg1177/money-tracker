<?php

use App\Services\Closeout\LegacyCloseoutDataBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Freeze existing closeout history: classic mode and personal closeout_scope.
     *
     * Snapshot reconstruction runs later, after
     * `2026_08_24_165852_split_necessity_and_expense_basis_flags` renames
     * `is_non_necessity` → `exclude_from_expense_basis`. The reconstructor
     * queries that current column name.
     */
    public function up(): void
    {
        app(LegacyCloseoutDataBackfill::class)->backfillModesAndScopes();
    }

    public function down(): void
    {
        // Historical closeout_scope values stay; reversing would drop reconstructed snapshots.
    }
};
