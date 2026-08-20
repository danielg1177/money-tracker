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
        Schema::table('debts', function (Blueprint $table) {
            $table->foreignId('reversed_from_debt_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('debts')
                ->nullOnDelete();
            $table->json('direction_reversals')->nullable()->after('interest_accruals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_from_debt_id');
            $table->dropColumn('direction_reversals');
        });
    }
};
