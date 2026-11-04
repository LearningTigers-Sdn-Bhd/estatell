<?php

namespace Database\Factories;

use App\Enums\ApiTokenKind;
use App\Models\ApiToken;
use App\Models\Property;
use App\Services\ApiAccess\ApiSecrets;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * A kiosk that is paired. Read the plaintext token from `ApiSecrets::newToken()` yourself and pass its hash, or use `withToken()`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'kind' => ApiTokenKind::Kiosk,
            'name' => 'Lobby kiosk',
            'device_name' => 'KIOSK-01',
            'token_hash' => ApiSecrets::hash(ApiSecrets::newToken()),
            'scopes' => ApiTokenKind::Kiosk->defaultScopes(),
            'paired_at' => now(),
        ];
    }

    /**
     * Set the token to a known plaintext so a test can send it.
     */
    public function withToken(string $plain): static
    {
        return $this->state(['token_hash' => ApiSecrets::hash($plain)]);
    }

    /**
     * A kiosk added by staff that has not paired yet; the code is valid for ten minutes.
     */
    public function awaitingPairing(string $code = 'ABCD2345'): static
    {
        return $this->state(fn () => [
            'device_name' => null,
            'token_hash' => null,
            'pairing_code_hash' => ApiSecrets::hash($code),
            'pairing_expires_at' => now()->addMinutes(10),
            'paired_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
