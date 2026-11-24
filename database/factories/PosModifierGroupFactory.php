<?php

namespace Database\Factories;

use App\Models\PosModifierGroup;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosModifierGroup>
 */
class PosModifierGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->words(2, true),
            'min_select' => 0,
            'max_select' => null,
            'max_per_option' => 1,
            'free_included_count' => 0,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
