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
        Schema::create('transaction_repayment_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('repayment_transaction_id')->index();
            $table->foreign('repayment_transaction_id')
                ->references('id')
                ->on('transactions')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('repaid_transaction_id')->index();
            $table->foreign('repaid_transaction_id')
                ->references('id')
                ->on('transactions')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('mirror_transaction_id')->nullable()->index();
            $table->foreign('mirror_transaction_id')
                ->references('id')
                ->on('transactions')
                ->nullOnDelete();
            $table->unsignedBigInteger('repaid_user_id')->index();
            $table->foreign('repaid_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_repayment')->default(false);
            $table->boolean('is_repaid')->default(false);
            $table->boolean('is_repayment_mirror')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['is_repayment', 'is_repaid', 'is_repayment_mirror']);
        });

        Schema::dropIfExists('transaction_repayment_links');
    }
};
