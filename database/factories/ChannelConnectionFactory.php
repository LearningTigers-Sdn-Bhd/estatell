<?php

namespace Database\Factories;

use App\Enums\ChannelConnectionStatus;
use App\Models\ChannelConnection;
use App\Models\ChannelProperty;
use App\Models\OtaChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelConnection>
 */
class ChannelConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_property_id' => ChannelProperty::factory(),
            'ota_channel_id' => OtaChannel::factory(),
            'external_channel_id' => fake()->unique()->uuid(),
            'settings' => ['hotel_id' => (string) fake()->numberBetween(1000000, 9999999)],
            'status' => ChannelConnectionStatus::Draft,
        ];
    }
}
