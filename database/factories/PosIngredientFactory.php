<?php

namespace Database\Factories;

use App\Models\PosIngredient;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosIngredient>
 */
class PosIngredientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->words(2, true),
            'base_unit' => 'g',
            'purchase_unit' => 'kg',
            'purchase_to_base_ratio' => 1000,
            'unit_cost' => 0,
            'active' => true,
        ];
    }
}
