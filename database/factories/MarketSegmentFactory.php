<?php

namespace Database\Factories;

use App\Models\MarketSegment;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketSegment>
 */
class MarketSegmentFactory extends Factory
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
            'name' => fake()->unique()->randomElement(['Leisure', 'Corporate', 'Government', 'Groups', 'Long stay', 'Wholesale', 'Complimentary', 'Crew']),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
