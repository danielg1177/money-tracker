<?php

use App\Models\Category;
use App\Models\CategoryUserDefault;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_necessity_default')->default(true)->after('split_default');
        });

        Category::query()
            ->where('is_expense', true)
            ->each(function (Category $category): void {
                $hohUserId = User::query()
                    ->where('family_id', $category->family_id)
                    ->where('role', 'head_of_household')
                    ->value('id');

                $necessityDefault = null;
                if ($hohUserId !== null) {
                    $necessityDefault = CategoryUserDefault::query()
                        ->where('category_id', $category->id)
                        ->where('user_id', $hohUserId)
                        ->value('is_necessity_default');
                }

                if ($necessityDefault === null) {
                    $necessityDefault = CategoryUserDefault::query()
                        ->where('category_id', $category->id)
                        ->orderBy('id')
                        ->value('is_necessity_default');
                }

                if ($necessityDefault !== null) {
                    $category->forceFill([
                        'is_necessity_default' => (bool) $necessityDefault,
                    ])->save();
                }
            });

        Schema::table('category_user_defaults', function (Blueprint $table) {
            $table->dropColumn('is_necessity_default');
        });
    }

    public function down(): void
    {
        Schema::table('category_user_defaults', function (Blueprint $table) {
            $table->boolean('is_necessity_default')->default(true)->after('exclude_from_expense_basis_default');
        });

        Category::query()
            ->where('is_expense', true)
            ->each(function (Category $category): void {
                CategoryUserDefault::query()
                    ->where('category_id', $category->id)
                    ->update([
                        'is_necessity_default' => (bool) $category->is_necessity_default,
                    ]);
            });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_necessity_default');
        });
    }
};
