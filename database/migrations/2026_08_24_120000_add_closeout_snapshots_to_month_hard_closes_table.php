<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('month_hard_closes', function (Blueprint $table) {
            $table->string('closeout_mode', 32)->nullable()->after('closed_by_user_id');
            $table->json('settings_snapshot')->nullable()->after('closeout_mode');
            $table->json('results_snapshot')->nullable()->after('settings_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('month_hard_closes', function (Blueprint $table) {
            $table->dropColumn(['closeout_mode', 'settings_snapshot', 'results_snapshot']);
        });
    }
};
