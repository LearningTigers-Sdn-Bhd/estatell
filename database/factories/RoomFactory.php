<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_type_id' => fn (array $attributes) => RoomType::factory()->create(['property_id' => $attributes['property_id']])->id,
            'number' => (string) fake()->unique()->numberBetween(100, 9999),
        ];
    }

    /**
     * Give every room its housekeeping status row, as CreateRoom and GenerateRooms do.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Room $room): void {
            if (! RoomStatus::query()->where('room_id', $room->id)->exists()) {
                RoomStatus::factory()->for($room)->create(['property_id' => $room->property_id]);
            }
        });
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
