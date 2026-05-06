<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $icons = ['📈', '📉', '🏠', '🍕', '🚗', '💳', '💰'];
        $isExpense = fake()->boolean();

        return [
            'family_id' => Family::factory(),
            'name' => fake()->word(),
            'icon' => fake()->randomElement($icons),
            'is_income' => ! $isExpense,
            'is_expense' => $isExpense,
            'is_split_default' => false,
            'split_default' => null,
        ];
    }
}
