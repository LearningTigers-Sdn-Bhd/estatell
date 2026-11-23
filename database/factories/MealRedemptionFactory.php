<?php

namespace Database\Factories;

use App\Models\MealPeriod;
use App\Models\MealRedemption;
use App\Models\ReservationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealRedemption>
 */
class MealRedemptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory()->checkedIn(),
            'property_id' => fn (array $attributes): int => ReservationUnit::query()->findOrFail((int) $attributes['reservation_unit_id'])->property_id,
            'meal_period_id' => fn (array $attributes): int => MealPeriod::factory()->create(['property_id' => $attributes['property_id']])->id,
            'service_date' => '2026-10-14',
            'redeemed_guests' => 1,
            'redeemed_at' => now(),
        ];
    }

    public function undone(): static
    {
        return $this->state(['undone_at' => now()]);
    }
}
