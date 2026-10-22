<?php

namespace Database\Factories;

use App\Models\ImportLink;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportLink>
 */
class ImportLinkFactory extends Factory
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
            'source_table' => 'bookings',
            'source_id' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            'target_type' => 'reservation',
            'target_id' => fake()->numberBetween(1, 1_000_000),
        ];
    }
}
