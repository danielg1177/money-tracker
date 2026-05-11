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
        Schema::create('plaid_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_id')->unique();
            $table->text('access_token');
            $table->string('institution_id')->nullable();
            $table->string('institution_name')->nullable();
            $table->longText('transactions_cursor')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('plaid_transaction_id')->nullable()->after('mirror_transaction_id');
            $table->string('import_source', 32)->nullable()->after('plaid_transaction_id');
            $table->unique(['family_id', 'plaid_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['family_id', 'plaid_transaction_id']);
            $table->dropColumn(['plaid_transaction_id', 'import_source']);
        });

        Schema::dropIfExists('plaid_items');
    }
};
