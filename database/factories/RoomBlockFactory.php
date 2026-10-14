<?php

namespace Database\Factories;

use App\Enums\RoomBlockReason;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomBlock>
 */
class RoomBlockFactory extends Factory
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
            'property_id' => fn (array $attributes) => Room::query()->findOrFail((int) $attributes['room_id'])->property_id,
            'reason' => RoomBlockReason::Maintenance,
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['completed_at' => now()]);
    }
}
