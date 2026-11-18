<?php

namespace Database\Factories;

use App\Enums\BookingEngineMode;
use App\Enums\PaymentMode;
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
            'payment_mode' => PaymentMode::Live,
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

    public function testPayments(): static
    {
        return $this->state(['payment_mode' => PaymentMode::Test]);
    }
}
