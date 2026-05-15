<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plaid_merchant_rules', function (Blueprint $table) {
            $table->string('description')->nullable()->after('is_split');
            $table->boolean('is_debt_payment')->default(false)->after('description');
            $table->unsignedBigInteger('debt_id')->nullable()->after('is_debt_payment');
            $table->json('split_data')->nullable()->after('debt_id');
        });
    }

    public function down(): void
    {
        Schema::table('plaid_merchant_rules', function (Blueprint $table) {
            $table->dropColumn(['split_data', 'debt_id', 'is_debt_payment', 'description']);
        });
    }
};
