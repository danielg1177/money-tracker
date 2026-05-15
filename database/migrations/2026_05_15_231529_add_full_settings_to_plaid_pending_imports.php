<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->string('suggested_description')->nullable()->after('suggested_is_non_necessity');
            $table->boolean('suggested_is_debt_payment')->default(false)->after('suggested_description');
            $table->unsignedBigInteger('suggested_debt_id')->nullable()->after('suggested_is_debt_payment');
            $table->json('suggested_split_data')->nullable()->after('suggested_debt_id');
        });
    }

    public function down(): void
    {
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->dropColumn(['suggested_split_data', 'suggested_debt_id', 'suggested_is_debt_payment', 'suggested_description']);
        });
    }
};
