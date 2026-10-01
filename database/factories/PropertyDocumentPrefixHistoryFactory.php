<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyDocumentPrefixHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyDocumentPrefixHistory>
 */
class PropertyDocumentPrefixHistoryFactory extends Factory
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
            'prefix' => fake()->unique()->lexify('???'),
        ];
    }
}
