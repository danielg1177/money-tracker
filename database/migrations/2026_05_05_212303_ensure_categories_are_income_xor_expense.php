<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Normalize legacy rows so each category is either income or expense, not both and not neither.
     */
    public function up(): void
    {
        DB::table('categories')
            ->where('is_income', false)
            ->where('is_expense', false)
            ->update([
                'is_expense' => true,
            ]);

        DB::table('categories')
            ->where('is_income', true)
            ->where('is_expense', true)
            ->where(function ($query): void {
                $query->where('is_split_default', true)
                    ->orWhereNotNull('advance_fund_id');
            })
            ->update([
                'is_income' => false,
            ]);

        DB::table('categories')
            ->where('is_income', true)
            ->where('is_expense', true)
            ->update([
                'is_expense' => false,
            ]);
    }
};
