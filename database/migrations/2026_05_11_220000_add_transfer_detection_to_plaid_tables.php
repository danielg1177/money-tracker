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
        Schema::table('plaid_merchant_rules', function (Blueprint $table): void {
            $table->string('action', 32)->default('categorize')->after('total_seen_count');
        });

        Schema::table('plaid_pending_imports', function (Blueprint $table): void {
            $table->boolean('is_transfer')->default(false)->after('raw_payload');
            $table->string('plaid_category_primary', 64)->nullable()->after('is_transfer');
            $table->string('plaid_category_detailed', 128)->nullable()->after('plaid_category_primary');
        });

        Schema::table('plaid_items', function (Blueprint $table): void {
            $table->string('account_type', 32)->nullable()->after('institution_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_items', function (Blueprint $table): void {
            $table->dropColumn('account_type');
        });

        Schema::table('plaid_pending_imports', function (Blueprint $table): void {
            $table->dropColumn([
                'plaid_category_detailed',
                'plaid_category_primary',
                'is_transfer',
            ]);
        });

        Schema::table('plaid_merchant_rules', function (Blueprint $table): void {
            $table->dropColumn('action');
        });
    }
};
