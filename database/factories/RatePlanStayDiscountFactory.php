<?php

namespace Database\Factories;

use App\Enums\StayDiscountType;
use App\Models\RatePlan;
use App\Models\RatePlanStayDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlanStayDiscount>
 */
class RatePlanStayDiscountFactory extends Factory
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
            'min_nights' => 3,
            'discount_type' => StayDiscountType::Percent,
            'value' => '10.00',
            'from_night' => 1,
        ];
    }
}
