<?php

namespace Database\Factories;

use App\Models\PosOutlet;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosTerminalDevice>
 */
class PosTerminalDeviceFactory extends Factory
{
    /**
     * A registered tablet with a random token hash. Use withToken() when the test must send the cookie.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => 'Counter tablet',
            'device_token_hash' => hash('sha256', fake()->unique()->sha256()),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(['device_token_hash' => hash('sha256', $token)]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
