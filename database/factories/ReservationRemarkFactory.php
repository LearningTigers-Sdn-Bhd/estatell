<?php

namespace Database\Factories;

use App\Enums\RemarkKind;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationRemark>
 */
class ReservationRemarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'property_id' => fn (array $attributes) => Reservation::query()->whereKey($attributes['reservation_id'])->value('property_id'),
            'kind' => RemarkKind::Housekeeping,
            'body' => fake()->sentence(),
        ];
    }
}
