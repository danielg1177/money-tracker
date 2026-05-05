<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('families')->where('id', 1)->exists()
            || ! DB::table('users')->where('id', 1)->exists()
            || ! DB::table('users')->where('id', 2)->exists()) {
            return;
        }

        $exists = DB::table('debts')
            ->where('family_id', 1)
            ->where('debtor_id', 2)
            ->where('creditor_id', 1)
            ->where('is_pending_closeout', 0)
            ->where('is_family_debt', 0)
            ->whereNull('transaction_id')
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('debts')->insert([
            'family_id' => 1,
            'debtor_id' => 2,
            'creditor_id' => 1,
            'fund_id' => null,
            'transaction_id' => null,
            'amount' => 28.00,
            'balance' => 28.00,
            'description' => 'Split settlements from 4/2026',
            'contributions' => json_encode([['month' => 4, 'year' => 2026, 'amount' => 28.00]]),
            'is_pending_closeout' => 0,
            'is_family_debt' => 0,
            'creditor_name' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void {}
};
