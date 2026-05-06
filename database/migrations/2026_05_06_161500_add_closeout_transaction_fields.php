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
        Schema::table('fund_rules', function (Blueprint $table) {
            $table->foreignId('closeout_expense_category_id')
                ->nullable()
                ->after('destination_title')
                ->constrained('categories')
                ->nullOnDelete();
        });

        Schema::table('closeout_title_savings', function (Blueprint $table) {
            $table->foreignId('completion_transaction_id')
                ->nullable()
                ->after('completed_at')
                ->constrained('transactions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('closeout_title_savings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('completion_transaction_id');
        });

        Schema::table('fund_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closeout_expense_category_id');
        });
    }
};
