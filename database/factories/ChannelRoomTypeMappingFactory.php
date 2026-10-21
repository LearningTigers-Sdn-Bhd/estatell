<?php

namespace Database\Factories;

use App\Models\ChannelProperty;
use App\Models\ChannelRoomTypeMapping;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelRoomTypeMapping>
 */
class ChannelRoomTypeMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_property_id' => ChannelProperty::factory(),
            'room_type_id' => RoomType::factory(),
            'external_room_type_id' => fake()->unique()->uuid(),
        ];
    }
}
