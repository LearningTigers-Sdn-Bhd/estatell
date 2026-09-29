<?php

namespace Database\Factories;

use App\Enums\HousekeepingStatus;
use App\Models\Room;
use App\Models\RoomStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomStatus>
 */
class RoomStatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'property_id' => fn (array $attributes) => Room::query()->findOrFail($attributes['room_id'])->property_id,
            'status' => HousekeepingStatus::Clean,
        ];
    }
}
