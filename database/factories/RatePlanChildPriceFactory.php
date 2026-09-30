<?php

namespace Database\Factories;

use App\Enums\AgeBandPricing;
use App\Models\PropertyAgeGroup;
use App\Models\RatePlan;
use App\Models\RatePlanChildPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlanChildPrice>
 */
class RatePlanChildPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rate_plan_id' => RatePlan::factory()->perGuest(),
            'property_age_group_id' => PropertyAgeGroup::factory(),
            'pricing' => AgeBandPricing::Free,
            'value' => null,
        ];
    }
}
