<?php

namespace Database\Factories;

use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\TransferRoute;
use App\Models\UnitTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A shared Transfer in by default. Tests pass `property_id`, `reservation_unit_id` and `transfer_route_id` of the same
 * property.
 *
 * @extends Factory<UnitTransfer>
 */
class UnitTransferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'reservation_unit_id' => ReservationUnit::factory(),
            'direction' => TransferDirection::In,
            'mode' => TransferMode::Shared,
            'transfer_route_id' => TransferRoute::factory(),
            'pickup_time' => '10:30',
            'flight_number' => null,
            'note' => null,
        ];
    }
}
