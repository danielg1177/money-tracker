<?php

namespace Database\Factories;

use App\Models\Family;
use App\Models\FamilyCloseoutRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyCloseoutRule>
 */
class FamilyCloseoutRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'name' => fake()->words(3, true),
            'order' => 1,
            'is_active' => true,
            'stage' => FamilyCloseoutRule::StageSurplus,
            'allocation_type' => 'percentage',
            'amount' => 10,
            'destination_type' => 'fund',
            'destination_id' => null,
            'destination_title' => null,
            'closeout_expense_category_id' => null,
        ];
    }
}
