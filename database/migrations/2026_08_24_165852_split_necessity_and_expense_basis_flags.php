<?php

use App\Models\CategoryUserDefault;
use App\Models\PlaidMerchantRule;
use App\Models\PlaidPendingImport;
use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('is_non_necessity', 'exclude_from_expense_basis');
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_necessity')->default(true)->after('exclude_from_expense_basis');
        });
        Transaction::query()->where('exclude_from_expense_basis', true)->update(['is_necessity' => false]);

        Schema::table('category_user_defaults', function (Blueprint $table) {
            $table->renameColumn('is_non_necessity_default', 'exclude_from_expense_basis_default');
        });
        Schema::table('category_user_defaults', function (Blueprint $table) {
            $table->boolean('is_necessity_default')->default(true)->after('exclude_from_expense_basis_default');
        });
        CategoryUserDefault::query()
            ->where('exclude_from_expense_basis_default', true)
            ->update(['is_necessity_default' => false]);

        Schema::table('plaid_merchant_rules', function (Blueprint $table) {
            $table->renameColumn('is_non_necessity', 'exclude_from_expense_basis');
        });
        Schema::table('plaid_merchant_rules', function (Blueprint $table) {
            $table->boolean('is_necessity')->default(true)->after('exclude_from_expense_basis');
        });
        PlaidMerchantRule::query()
            ->where('exclude_from_expense_basis', true)
            ->update(['is_necessity' => false]);

        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->renameColumn('suggested_is_non_necessity', 'suggested_exclude_from_expense_basis');
        });
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->boolean('suggested_is_necessity')->default(true)->after('suggested_exclude_from_expense_basis');
        });
        PlaidPendingImport::query()
            ->where('suggested_exclude_from_expense_basis', true)
            ->update(['suggested_is_necessity' => false]);
    }

    public function down(): void
    {
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->dropColumn('suggested_is_necessity');
        });
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->renameColumn('suggested_exclude_from_expense_basis', 'suggested_is_non_necessity');
        });

        Schema::table('plaid_merchant_rules', function (Blueprint $table) {
            $table->dropColumn('is_necessity');
        });
        Schema::table('plaid_merchant_rules', function (Blueprint $table) {
            $table->renameColumn('exclude_from_expense_basis', 'is_non_necessity');
        });

        Schema::table('category_user_defaults', function (Blueprint $table) {
            $table->dropColumn('is_necessity_default');
        });
        Schema::table('category_user_defaults', function (Blueprint $table) {
            $table->renameColumn('exclude_from_expense_basis_default', 'is_non_necessity_default');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('is_necessity');
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('exclude_from_expense_basis', 'is_non_necessity');
        });
    }
};
