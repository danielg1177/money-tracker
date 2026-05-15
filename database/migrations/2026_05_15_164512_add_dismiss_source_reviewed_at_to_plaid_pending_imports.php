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
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->string('dismiss_source', 16)->nullable()->after('is_transfer');
            $table->timestamp('reviewed_at')->nullable()->after('dismiss_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_pending_imports', function (Blueprint $table) {
            $table->dropColumn(['dismiss_source', 'reviewed_at']);
        });
    }
};
