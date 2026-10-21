<?php

namespace Database\Factories;

use App\Models\ChannelConnection;
use App\Models\ChannelOtaMapping;
use App\Models\ChannelRatePlanMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelOtaMapping>
 */
class ChannelOtaMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_connection_id' => ChannelConnection::factory(),
            'channel_rate_plan_mapping_id' => ChannelRatePlanMapping::factory(),
            'occupancy' => 2,
            'ota_room_code' => (string) fake()->numberBetween(100000, 999999),
            'ota_rate_code' => (string) fake()->numberBetween(100000, 999999),
            'is_primary' => true,
            'rate_type' => null,
        ];
    }
}
