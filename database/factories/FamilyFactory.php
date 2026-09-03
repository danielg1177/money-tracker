<?php

namespace Database\Factories;

use App\Models\Family;
use App\Services\Closeout\CloseoutMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'description' => fake()->boolean(70) ? fake()->sentence() : null,
            'closeout_mode' => CloseoutMode::Classic,
        ];
    }
}
