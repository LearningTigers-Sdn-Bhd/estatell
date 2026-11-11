<?php

namespace Database\Factories;

use App\Models\RoomRevenuePolicy;
use App\Models\RoomRevenuePolicyStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomRevenuePolicyStep>
 */
class RoomRevenuePolicyStepFactory extends Factory
{
    /**
     * A free step at 0 days, as a Percentage policy has it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_revenue_policy_id' => RoomRevenuePolicy::factory(),
            'property_id' => fn (array $attributes): int => RoomRevenuePolicy::query()->findOrFail((int) $attributes['room_revenue_policy_id'])->property_id,
            'days_before' => 0,
            'amount' => null,
            'percent' => '0.00',
        ];
    }
}
