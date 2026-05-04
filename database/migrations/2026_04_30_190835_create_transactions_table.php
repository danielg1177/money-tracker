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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->decimal('amount', 14, 2);
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->boolean('is_split')->default(false);
            $table->json('split_data')->nullable();
            $table->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_borrow')->default(false);
            $table->boolean('is_debt_payment')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
