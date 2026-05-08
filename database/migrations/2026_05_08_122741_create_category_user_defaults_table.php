<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('category_user_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advance_fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->boolean('is_non_necessity_default')->default(false);
            $table->timestamps();

            $table->unique(['category_id', 'user_id']);
        });

        $headOfHouseholdByFamily = DB::table('users')
            ->select('id', 'family_id')
            ->whereNotNull('family_id')
            ->where('role', 'head_of_household')
            ->orderBy('id')
            ->get()
            ->groupBy('family_id')
            ->map(fn ($users) => (int) $users->first()->id);

        $categoryRows = DB::table('categories')
            ->select('id', 'family_id', 'advance_fund_id', 'is_non_necessity_default')
            ->where(function ($query): void {
                $query->whereNotNull('advance_fund_id')
                    ->orWhere('is_non_necessity_default', true);
            })
            ->get();

        $rowsToInsert = [];
        $now = now();
        foreach ($categoryRows as $categoryRow) {
            $familyId = (int) $categoryRow->family_id;
            $headOfHouseholdId = $headOfHouseholdByFamily->get($familyId);
            if ($headOfHouseholdId === null) {
                continue;
            }

            $rowsToInsert[] = [
                'category_id' => (int) $categoryRow->id,
                'user_id' => (int) $headOfHouseholdId,
                'advance_fund_id' => $categoryRow->advance_fund_id ? (int) $categoryRow->advance_fund_id : null,
                'is_non_necessity_default' => (bool) $categoryRow->is_non_necessity_default,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rowsToInsert !== []) {
            DB::table('category_user_defaults')->insert($rowsToInsert);
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('advance_fund_id');
            $table->dropColumn('is_non_necessity_default');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('advance_fund_id')->nullable()->after('split_default')->constrained('funds')->nullOnDelete();
            $table->boolean('is_non_necessity_default')->default(false)->after('advance_fund_id');
        });

        Schema::dropIfExists('category_user_defaults');
    }
};
