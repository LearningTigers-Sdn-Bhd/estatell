<?php

namespace Database\Factories;

use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\ChargeItem;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlanInclusion>
 */
class RatePlanInclusionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_plan_id' => RatePlan::factory(),
            'charge_item_id' => fn (array $attributes) => ChargeItem::factory()->create([
                'property_id' => RatePlan::query()->findOrFail($attributes['rate_plan_id'])->property_id,
            ])->id,
            'meal_period_id' => null,
            'charging_unit' => ChargingUnit::PerGuestNight,
            'quantity' => 1,
            'allocated_amount' => '25.00',
            'starts_on' => InclusionStart::DayAfterArrival,
            'ends_on' => InclusionEnd::DepartureDay,
            'position' => 0,
        ];
    }
}
