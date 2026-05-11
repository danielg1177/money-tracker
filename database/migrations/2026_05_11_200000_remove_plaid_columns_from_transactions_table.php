<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MySQL may use the composite unique on (`family_id`, `plaid_transaction_id`) as the supporting
     * index for the `family_id` foreign key. Add a dedicated `family_id` index first, then drop the
     * composite unique and columns. The extra index remains (alongside the FK index) if both exist;
     * that is harmless for correctness.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index('family_id', 'transactions_family_id_plaid_migration_idx');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['family_id', 'plaid_transaction_id']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['plaid_transaction_id', 'import_source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('plaid_transaction_id')->nullable()->after('mirror_transaction_id');
            $table->string('import_source', 32)->nullable()->after('plaid_transaction_id');
            $table->unique(['family_id', 'plaid_transaction_id']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_family_id_plaid_migration_idx');
        });
    }
};
