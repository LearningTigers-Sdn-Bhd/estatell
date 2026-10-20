<?php

namespace Database\Factories;

use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelRatePlanMapping>
 */
class ChannelRatePlanMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_property_id' => ChannelProperty::factory(),
            'rate_plan_id' => RatePlan::factory(),
            'room_type_id' => RoomType::factory(),
            'external_rate_plan_id' => fake()->unique()->uuid(),
        ];
    }
}
