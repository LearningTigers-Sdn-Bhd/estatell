<?php

namespace Database\Factories;

use App\Enums\IdentityDocumentType;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestIdentityDocument>
 */
class GuestIdentityDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_id' => Guest::factory(),
            'type' => IdentityDocumentType::MyKad,
            'number' => fake()->numerify('############'),
            'issuing_country_code' => 'MY',
            'expires_on' => null,
        ];
    }

    public function passport(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => IdentityDocumentType::Passport,
            'number' => 'A'.fake()->numerify('########'),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_on' => now()->subDay()->toDateString()]);
    }
}
