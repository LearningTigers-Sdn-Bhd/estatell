<?php

namespace Database\Factories;

use App\Enums\LockProvider;
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

    /**
     * An eLock lock: opened by a card Keyport writes, so it has a lock number and no TTLock id.
     */
    public function elock(string $lockNo = '1.1.101'): static
    {
        return $this->state(['provider' => LockProvider::ELock, 'ttlock_lock_id' => null, 'lock_no' => $lockNo, 'name' => 'Room '.$lockNo, 'battery_percent' => null]);
    }

    public function withGateway(): static
    {
        return $this->state(['has_gateway' => true]);
    }
}
