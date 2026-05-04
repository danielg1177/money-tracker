<?php

namespace Database\Factories;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 10, 1000);

        return [
            'family_id' => null,
            'debtor_id' => null,
            'creditor_id' => null,
            'fund_id' => null,
            'transaction_id' => null,
            'amount' => $amount,
            'balance' => $amount,
            'description' => fake()->sentence(),
        ];
    }
}
