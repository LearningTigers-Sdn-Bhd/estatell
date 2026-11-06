<?php

namespace Database\Factories;

use App\Models\Boat;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boat>
 */
class BoatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'SA'.fake()->unique()->numberBetween(10000, 99999),
            'registration' => null,
            'active' => true,
        ];
    }
}
