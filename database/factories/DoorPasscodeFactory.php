<?php

namespace Database\Factories;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoorPasscode>
 */
class DoorPasscodeFactory extends Factory
{
    /**
     * An active generated PIN for the next two days.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => fn (array $attributes): int => (int) DoorLock::query()->whereKey($attributes['door_lock_id'])->value('property_id'),
            'door_lock_id' => DoorLock::factory(),
            'reservation_unit_id' => null,
            'kind' => PasscodeKind::Generated,
            'status' => PasscodeStatus::Active,
            'code' => fake()->numerify('######'),
            'ttlock_passcode_id' => fake()->unique()->numberBetween(100000, 999999),
            'label' => fake()->name(),
            'starts_at' => now(),
            'ends_at' => now()->addDays(2),
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }
}
