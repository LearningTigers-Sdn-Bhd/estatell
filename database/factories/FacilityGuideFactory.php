<?php

namespace Database\Factories;

use App\Models\FacilityGuide;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An own-staff driver by default.
 *
 * @extends Factory<FacilityGuide>
 */
class FacilityGuideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->firstName().' '.fake()->numberBetween(1, 999),
            'phone' => null,
            'active' => true,
        ];
    }
}
