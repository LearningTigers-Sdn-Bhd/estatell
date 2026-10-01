<?php

namespace Database\Factories;

use App\Models\ReservationUnit;
use App\Models\ReservationUnitChild;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationUnitChild>
 */
class ReservationUnitChildFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'age' => fake()->numberBetween(0, 12),
        ];
    }
}
