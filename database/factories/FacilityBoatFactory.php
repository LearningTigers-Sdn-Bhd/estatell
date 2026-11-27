<?php

namespace Database\Factories;

use App\Models\FacilityBoat;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An own-staff driver by default.
 *
 * @extends Factory<FacilityBoat>
 */
class FacilityBoatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Boat '.fake()->unique()->numberBetween(1, 9999),
            'seats' => 12,
            'active' => true,
        ];
    }
}
