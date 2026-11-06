<?php

namespace Database\Factories;

use App\Features\Kiosks;
use App\Models\Kiosk;
use App\Models\Property;
use App\Services\ApiAccess\ApiSecrets;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Pennant\Feature;

/**
 * @extends Factory<Kiosk>
 */
class KioskFactory extends Factory
{
    /**
     * A kiosk only exists while its property has the Kiosks feature, so creating one turns the feature on.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Kiosk $kiosk) => Feature::for($kiosk->property)->activate(Kiosks::class));
    }

    /**
     * A paired kiosk. Use `withToken()` to give it a token a test can send.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Lobby kiosk',
            'device_name' => 'KIOSK-01',
            'paired_at' => now(),
        ];
    }

    /**
     * Give the kiosk a Sanctum token with a known plaintext so a test can send it.
     *
     * @param  list<string>  $abilities
     */
    public function withToken(string $plain, array $abilities = [Kiosk::TOKEN_ABILITY]): static
    {
        return $this->afterCreating(fn (Kiosk $kiosk) => $kiosk->tokens()->forceCreate([
            'name' => $kiosk->name,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
        ]));
    }

    /**
     * A kiosk added by staff that has not paired yet; the code is valid for ten minutes.
     */
    public function awaitingPairing(string $code = 'ABCD2345'): static
    {
        return $this->state(fn () => [
            'device_name' => null,
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
