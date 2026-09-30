<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyAgeGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyAgeGroup>
 */
class PropertyAgeGroupFactory extends Factory
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
            'label' => fake()->unique()->words(2, true),
            'min_age' => 12,
            'max_age' => 17,
        ];
    }
}
