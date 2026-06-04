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
        Schema::table('fund_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('plaid_pending_import_id')->nullable()->after('transaction_id');
            $table->string('plaid_transaction_id')->nullable()->after('plaid_pending_import_id');
            $table->foreign('plaid_pending_import_id')->references('id')->on('plaid_pending_imports')->nullOnDelete();
        });

        Schema::table('plaid_pending_imports', function (Blueprint $table): void {
            $table->unsignedBigInteger('suggested_sweep_match_id')->nullable()->after('ledger_match_score');
            $table->decimal('sweep_match_score', 5, 4)->nullable()->after('suggested_sweep_match_id');
            $table->unsignedBigInteger('fund_movement_id')->nullable()->after('sweep_match_score');
            $table->foreign('suggested_sweep_match_id')->references('id')->on('fund_movements')->nullOnDelete();
            $table->foreign('fund_movement_id')->references('id')->on('fund_movements')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('plaid_pending_imports', 'fund_movement_id')) {
            Schema::table('plaid_pending_imports', function (Blueprint $table): void {
                $table->dropForeign(['fund_movement_id']);
            });
        }

        if (Schema::hasColumn('plaid_pending_imports', 'suggested_sweep_match_id')) {
            Schema::table('plaid_pending_imports', function (Blueprint $table): void {
                $table->dropForeign(['suggested_sweep_match_id']);
            });
        }

        if (Schema::hasColumn('plaid_pending_imports', 'suggested_sweep_match_id')) {
            Schema::table('plaid_pending_imports', function (Blueprint $table): void {
                $table->dropColumn(['suggested_sweep_match_id', 'sweep_match_score', 'fund_movement_id']);
            });
        }

        if (Schema::hasColumn('fund_movements', 'plaid_pending_import_id')) {
            Schema::table('fund_movements', function (Blueprint $table): void {
                $table->dropForeign(['plaid_pending_import_id']);
            });
        }

        if (Schema::hasColumn('fund_movements', 'plaid_transaction_id')) {
            Schema::table('fund_movements', function (Blueprint $table): void {
                $table->dropColumn(['plaid_pending_import_id', 'plaid_transaction_id']);
            });
        }
    }
};
