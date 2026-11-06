<?php

namespace Database\Factories;

use App\Enums\PolicyChargeMode;
use App\Enums\PolicyScope;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationCancellationTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationCancellationTerm>
 */
class ReservationCancellationTermFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'reservation_id' => Reservation::factory(),
            'charge_mode' => PolicyChargeMode::Free,
            'amount' => null,
            'percent' => null,
            'percent_basis' => null,
            'applies_to' => PolicyScope::FirstNight,
            'free_until_days' => null,
            'text_en' => 'No charge for cancellation.',
            'text_ms' => 'Tiada caj untuk pembatalan.',
        ];
    }
}
