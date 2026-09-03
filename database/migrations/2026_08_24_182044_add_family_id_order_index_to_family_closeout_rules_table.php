<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_closeout_rules', function (Blueprint $table) {
            $table->index(['family_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::table('family_closeout_rules', function (Blueprint $table) {
            $table->dropIndex(['family_id', 'order']);
        });
    }
};
