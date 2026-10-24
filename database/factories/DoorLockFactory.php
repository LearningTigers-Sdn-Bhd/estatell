<?php

namespace Database\Factories;

use App\Models\DoorLock;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoorLock>
 */
class DoorLockFactory extends Factory
{
    /**
     * A lock without a gateway and without a room.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_id' => null,
            'ttlock_lock_id' => fake()->unique()->numberBetween(100000, 999999),
            'name' => 'C13_'.fake()->unique()->numerify('######'),
            'battery_percent' => 90,
            'has_gateway' => false,
            'synced_at' => now(),
        ];
    }

    public function withGateway(): static
    {
        return $this->state(['has_gateway' => true]);
    }
}
