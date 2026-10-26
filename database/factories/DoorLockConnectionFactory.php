<?php

namespace Database\Factories;

use App\Enums\PasscodeMode;
use App\Models\DoorLockConnection;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoorLockConnection>
 */
class DoorLockConnectionFactory extends Factory
{
    /**
     * An enabled connection with fresh tokens.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'ttlock_username' => fake()->unique()->safeEmail(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_expires_at' => now()->addDays(30),
            'enabled' => true,
            'passcode_mode' => PasscodeMode::Generated,
        ];
    }
}
