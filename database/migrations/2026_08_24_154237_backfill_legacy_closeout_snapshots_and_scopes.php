<?php

use App\Services\Closeout\LegacyCloseoutDataBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Freeze existing closeout history: classic mode, personal closeout_scope,
     * and artifact-based snapshots (not today’s live FundRules).
     */
    public function up(): void
    {
        app(LegacyCloseoutDataBackfill::class)->run();
    }

    public function down(): void
    {
        // Historical amounts stay frozen; reversing would drop reconstructed snapshots.
    }
};
