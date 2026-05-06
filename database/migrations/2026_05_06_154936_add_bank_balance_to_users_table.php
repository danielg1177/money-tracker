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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('bank_balance_enabled')->default(false)->after('is_admin');
            $table->decimal('bank_balance', 15, 2)->nullable()->after('bank_balance_enabled');
            $table->date('bank_balance_set_at')->nullable()->after('bank_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bank_balance_enabled', 'bank_balance', 'bank_balance_set_at']);
        });
    }
};
