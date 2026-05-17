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
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('plaid_pending_import_id')->nullable()->after('import_source');
            $table->foreign('plaid_pending_import_id')->references('id')->on('plaid_pending_imports')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['plaid_pending_import_id']);
            $table->dropColumn('plaid_pending_import_id');
        });
    }
};
