<?php

use App\Services\Closeout\LegacyCloseoutDataBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Reconstruct pre-snapshot hard closes from ledger artifacts.
     *
     * Runs after `exclude_from_expense_basis` exists (2026_08_24_165852).
     * Skips months that already have a results_snapshot.
     */
    public function up(): void
    {
        app(LegacyCloseoutDataBackfill::class)->backfillHardCloseSnapshots();
    }

    public function down(): void
    {
        // Historical amounts stay frozen; reversing would drop reconstructed snapshots.
    }
};
