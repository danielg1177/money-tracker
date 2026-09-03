<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_closeout_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('stage', 32);
            $table->string('allocation_type', 16);
            $table->decimal('amount', 14, 2);
            $table->string('destination_type', 16);
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->string('destination_title')->nullable();
            $table->foreignId('closeout_expense_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_closeout_rules');
    }
};
