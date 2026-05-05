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
        // Some database drivers do not reliably apply `->change()` together
        // with `->after()` in a single blueprint. Split into two steps so closeout
        // columns exist and `fund_id` can be nullable for destination-based rules.
        Schema::table('fund_rules', function (Blueprint $table) {
            $table->string('destination_type', 16)->default('fund');
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->string('destination_title', 255)->nullable();
        });

        Schema::table('fund_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fund_rules', function (Blueprint $table) {
            $table->dropColumn(['destination_type', 'destination_id', 'destination_title']);
        });
    }
};
