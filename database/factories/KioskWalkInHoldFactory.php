<?php

namespace Database\Factories;

use App\Models\KioskWalkInHold;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KioskWalkInHold> */
class KioskWalkInHoldFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'property_id' => fn (array $attributes): int => ReservationUnit::query()->whereKey($attributes['reservation_unit_id'])->sole()->property_id,
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
