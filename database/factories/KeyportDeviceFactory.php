<?php

namespace Database\Factories;

use App\Models\KeyportDevice;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KeyportDevice>
 */
class KeyportDeviceFactory extends Factory
{
    /**
     * A paired PC with a random token. Use withToken() when the test must send the token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => 'dev_'.Str::ulid(),
            'property_id' => Property::factory(),
            'name' => 'Front desk PC',
            'platform' => 'darwin-arm64',
            'agent_version' => '0.1.0',
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => hash('sha256', $token)]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
