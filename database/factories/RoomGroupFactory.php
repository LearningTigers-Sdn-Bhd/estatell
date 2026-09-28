<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\RoomGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomGroup>
 */
class RoomGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
