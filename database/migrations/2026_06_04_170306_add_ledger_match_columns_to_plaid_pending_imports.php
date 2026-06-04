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
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->unsignedBigInteger('suggested_ledger_match_id')->nullable()->after('transaction_id');
            $table->decimal('ledger_match_score', 5, 4)->nullable()->after('suggested_ledger_match_id');
            $table->foreign('suggested_ledger_match_id')->references('id')->on('transactions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->dropForeign(['suggested_ledger_match_id']);
            $table->dropColumn(['suggested_ledger_match_id', 'ledger_match_score']);
        });
    }
};
