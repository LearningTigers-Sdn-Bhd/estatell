<?php

namespace Database\Factories;

use App\Models\AiConnection;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConnection>
 */
class AiConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'n8n – WhatsApp',
        ];
    }

    /**
     * Give the connection a Sanctum token with a known plaintext so a test can send it.
     *
     * @param  list<string>  $abilities
     */
    public function withToken(string $plain, array $abilities = [AiConnection::TOKEN_ABILITY]): static
    {
        return $this->afterCreating(fn (AiConnection $connection) => $connection->tokens()->forceCreate([
            'name' => $connection->name,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
        ]));
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
