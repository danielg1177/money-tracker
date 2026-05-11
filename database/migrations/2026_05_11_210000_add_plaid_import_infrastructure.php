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
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('plaid_transaction_id')->nullable()->after('mirror_transaction_id');
            $table->string('import_source', 32)->nullable()->after('plaid_transaction_id');
            $table->unique(['family_id', 'plaid_transaction_id'], 'transactions_family_plaid_unique');
        });

        Schema::create('plaid_pending_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plaid_item_id')->constrained('plaid_items')->cascadeOnDelete();
            $table->string('plaid_transaction_id')->unique();
            $table->string('plaid_account_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->string('merchant_name')->nullable();
            $table->string('raw_name');
            $table->unsignedBigInteger('suggested_category_id')->nullable();
            $table->string('suggested_type', 16)->default('expense');
            $table->unsignedBigInteger('suggested_fund_id')->nullable();
            $table->unsignedBigInteger('suggested_advance_fund_id')->nullable();
            $table->boolean('suggested_is_non_necessity')->default(false);
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->string('status', 32)->default('pending');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('plaid_merchant_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_key');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('type', 16)->default('expense');
            $table->unsignedBigInteger('fund_id')->nullable();
            $table->unsignedBigInteger('advance_fund_id')->nullable();
            $table->boolean('is_non_necessity')->default(false);
            $table->boolean('is_split')->default(false);
            $table->unsignedInteger('confirmation_count')->default(0);
            $table->unsignedInteger('total_seen_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'merchant_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_merchant_rules');
        Schema::dropIfExists('plaid_pending_imports');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_family_plaid_unique');
            $table->dropColumn(['plaid_transaction_id', 'import_source']);
        });
    }
};
