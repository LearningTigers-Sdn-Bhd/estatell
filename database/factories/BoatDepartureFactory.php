<?php

namespace Database\Factories;

use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoatDeparture>
 */
class BoatDepartureFactory extends Factory
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
            'direction' => BoatDirection::In,
            'leaves_at' => '09:00',
            'arrives_at' => '09:45',
            'pickup_point' => 'Kuala Besut jetty',
            'seats' => 20,
            'charge_item_id' => null,
            'active' => true,
        ];
    }

    public function out(): static
    {
        return $this->state(fn (array $attributes) => ['direction' => BoatDirection::Out]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
