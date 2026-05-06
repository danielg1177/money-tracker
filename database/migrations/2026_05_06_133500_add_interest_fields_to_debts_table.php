<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->boolean('interest_enabled')->default(false)->after('contributions');
            $table->decimal('interest_rate', 5, 2)->nullable()->after('interest_enabled');
            $table->date('interest_last_applied_at')->nullable()->after('interest_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->dropColumn([
                'interest_enabled',
                'interest_rate',
                'interest_last_applied_at',
            ]);
        });
    }
};
