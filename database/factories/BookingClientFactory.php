<?php

namespace Database\Factories;

use App\Enums\BookingClientType;
use App\Models\BookingClient;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingClient>
 */
class BookingClientFactory extends Factory
{
    /**
     * The microsite app. Use `withToken()` to give it a token a test can send.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => BookingClientType::Microsite,
            'name' => 'Booking microsite',
        ];
    }

    public function apiOnly(Property $property): static
    {
        return $this->state(['type' => BookingClientType::ApiOnly, 'property_id' => $property->id, 'name' => $property->name]);
    }

    public function demo(): static
    {
        return $this->state(['type' => BookingClientType::Demo, 'name' => 'Demo test client']);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    /**
     * Give the client a Sanctum token with a known plaintext. With no abilities, the token gets those of the client's type.
     *
     * @param  list<string>|null  $abilities
     */
    public function withToken(string $plain, ?array $abilities = null): static
    {
        return $this->afterCreating(fn (BookingClient $client) => $client->tokens()->forceCreate([
            'name' => $client->name,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities ?? $client->type->abilities(),
        ]));
    }
}
