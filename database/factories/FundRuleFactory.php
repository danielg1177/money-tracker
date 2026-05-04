<?php

namespace Database\Factories;

use App\Models\FundRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundRule>
 */
class FundRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'fund_id' => null,
            'name' => fake()->word(),
            'order' => fake()->numberBetween(0, 10),
            'allocation_type' => fake()->randomElement(['percentage', 'fixed']),
            'amount' => fake()->randomFloat(2, 1, 100),
            'allocation_base' => fake()->randomElement(['gross_income', 'net_income', 'remaining']),
            'is_active' => true,
        ];
    }
}
