<?php

namespace Database\Factories;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A scheduled Boat in trip by default. Tests pass `property_id` and `reservation_unit_id` of the same property.
 *
 * @extends Factory<UnitBoatTrip>
 */
class UnitBoatTripFactory extends Factory
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
            'reservation_unit_id' => ReservationUnit::factory(),
            'direction' => BoatDirection::In,
            'mode' => BoatMode::Scheduled,
            'boat_departure_id' => BoatDeparture::factory(),
            'note' => null,
        ];
    }

    public function out(): static
    {
        return $this->state(fn (array $attributes) => ['direction' => BoatDirection::Out]);
    }

    public function charter(): static
    {
        return $this->state(fn (array $attributes) => ['mode' => BoatMode::Charter, 'boat_departure_id' => null]);
    }

    public function own(): static
    {
        return $this->state(fn (array $attributes) => ['mode' => BoatMode::Own, 'boat_departure_id' => null]);
    }
}
