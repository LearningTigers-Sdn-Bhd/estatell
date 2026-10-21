<?php

namespace Database\Factories;

use App\Enums\ChannelAlertEvent;
use App\Models\ChannelAlert;
use App\Models\ChannelProperty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelAlert>
 */
class ChannelAlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_property_id' => ChannelProperty::factory(),
            'event' => ChannelAlertEvent::SyncError,
            'payload' => [],
        ];
    }
}
