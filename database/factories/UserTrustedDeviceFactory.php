<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserTrustedDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserTrustedDevice>
 */
class UserTrustedDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', Str::random(40)),
            'device_name' => 'Chrome on macOS',
            'last_used_at' => now(),
            'expires_at' => now()->addDays(90),
        ];
    }
}
