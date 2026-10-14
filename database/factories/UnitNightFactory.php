<?php

namespace Database\Factories;

use App\Models\ReservationUnit;
use App\Models\UnitNight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitNight>
 */
class UnitNightFactory extends Factory
{
    /**
     * Define the model's default state: an unassigned night priced at 100.00 with no extras.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory(),
            'date' => '2026-11-02',
            'rate_plan_id' => fn (array $attributes): int => ReservationUnit::query()->findOrFail((int) $attributes['reservation_unit_id'])->rate_plan_id,
            'base_amount' => '100.00',
            'extra_amount' => '0.00',
            'supplement_amount' => '0.00',
            'discount_amount' => '0.00',
            'amount' => '100.00',
        ];
    }
}
