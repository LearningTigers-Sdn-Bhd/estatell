<?php

namespace Database\Factories;

use App\Features\Pos;
use App\Models\PosApiClient;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Pennant\Feature;

/**
 * @extends Factory<PosApiClient>
 */
class PosApiClientFactory extends Factory
{
    /**
     * A client only exists while its property has POS on, so creating one turns the feature on.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (PosApiClient $client) => Feature::for($client->property)->activate(Pos::class));
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Restaurant POS',
        ];
    }

    /**
     * Give the client a Sanctum token with a known plaintext so a test can send it.
     *
     * @param  list<string>  $abilities
     */
    public function withToken(string $plain, array $abilities = [PosApiClient::TOKEN_ABILITY]): static
    {
        return $this->afterCreating(fn (PosApiClient $client) => $client->tokens()->forceCreate([
            'name' => $client->name,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
        ]));
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
