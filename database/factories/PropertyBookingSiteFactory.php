<?php

namespace Database\Factories;

use App\Enums\BookingEngineMode;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyBookingSite>
 */
class PropertyBookingSiteFactory extends Factory
{
    /**
     * An unpublished Microsite with nothing chosen yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'mode' => BookingEngineMode::Microsite,
        ];
    }

    public function apiOnly(): static
    {
        return $this->state(['mode' => BookingEngineMode::ApiOnly]);
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()]);
    }
}
