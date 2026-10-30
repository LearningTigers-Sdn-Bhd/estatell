<?php

namespace Database\Factories;

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\DoorLock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoorCard>
 */
class DoorCardFactory extends Factory
{
    /**
     * An active card for the next two days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => fn (array $attributes): int => (int) DoorLock::query()->whereKey($attributes['door_lock_id'])->value('property_id'),
            'door_lock_id' => DoorLock::factory(),
            'reservation_unit_id' => null,
            'card_number' => fake()->unique()->numberBetween(100000000, 4000000000),
            'ttlock_card_id' => fake()->unique()->numberBetween(100000, 999999),
            'status' => PasscodeStatus::Active,
            'label' => fake()->name(),
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }
}
