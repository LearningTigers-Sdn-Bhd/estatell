<?php

namespace Database\Factories;

use App\Models\BoatTripAddOn;
use App\Models\UnitAddOn;
use App\Models\UnitBoatTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoatTripAddOn>
 */
class BoatTripAddOnFactory extends Factory
{
    /**
     * Define the model's default state. Tests pass the trip and add-on of the same unit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_boat_trip_id' => UnitBoatTrip::factory(),
            'unit_add_on_id' => UnitAddOn::factory(),
        ];
    }
}
