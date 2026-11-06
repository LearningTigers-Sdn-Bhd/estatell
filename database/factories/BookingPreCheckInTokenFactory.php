<?php

namespace Database\Factories;

use App\Models\BookingPreCheckInToken;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BookingPreCheckInToken> */
class BookingPreCheckInTokenFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'property_id' => fn (array $attributes): int => ReservationUnit::query()->whereKey($attributes['reservation_unit_id'])->sole()->property_id,
            'token_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addDay(),
        ];
    }
}
