<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_debt_payment_benefit')->default(false)->after('is_debt_payment');
            $table->foreignId('debt_payment_income_id')
                ->nullable()
                ->after('mirror_transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('debt_payment_income_id');
            $table->dropColumn('is_debt_payment_benefit');
        });
    }
};
