<?php

namespace Database\Factories;

use App\Models\ReservationCancellationTerm;
use App\Models\ReservationCancellationTermStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationCancellationTermStep>
 */
class ReservationCancellationTermStepFactory extends Factory
{
    /**
     * A free step at 0 days, as a Percentage policy has it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_cancellation_term_id' => ReservationCancellationTerm::factory(),
            'property_id' => fn (array $attributes): int => ReservationCancellationTerm::query()->findOrFail((int) $attributes['reservation_cancellation_term_id'])->property_id,
            'days_before' => 0,
            'amount' => null,
            'percent' => '0.00',
        ];
    }
}
