<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => null,
            'user_id' => null,
            'category_id' => null,
            'type' => fake()->randomElement(['income', 'expense']),
            'amount' => fake()->randomFloat(2, 1, 10000),
            'description' => fake()->boolean(70) ? fake()->sentence() : null,
            'transaction_date' => fake()->dateTimeThisYear()->format('Y-m-d'),
            'is_split' => false,
            'is_borrow' => false,
            'is_debt_payment' => false,
        ];
    }
}
