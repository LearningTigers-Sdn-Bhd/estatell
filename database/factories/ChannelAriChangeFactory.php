<?php

namespace Database\Factories;

use App\Enums\AriChangeKind;
use App\Models\ChannelAriChange;
use App\Models\ChannelProperty;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelAriChange>
 */
class ChannelAriChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_property_id' => ChannelProperty::factory(),
            'room_type_id' => RoomType::factory(),
            'rate_plan_id' => null,
            'kind' => AriChangeKind::Availability,
            'date_from' => now()->toDateString(),
            'date_to' => now()->addDays(2)->toDateString(),
        ];
    }
}
