<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomCleaning>
 */
class RoomCleaningFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_id' => Room::factory(),
            'date' => now()->toDateString(),
            'housekeeper_id' => User::factory(),
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'created_by' => User::factory(),
        ];
    }

    public function open(): static
    {
        return $this->state(['finished_at' => null]);
    }
}
