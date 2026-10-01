<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+60'.fake()->numerify('1#########'),
            'nationality_code' => 'MY',
            'country_code' => 'MY',
        ];
    }

    public function vip(): static
    {
        return $this->state(fn (array $attributes) => ['is_vip' => true]);
    }

    public function blacklisted(string $reason = 'Damaged the room'): static
    {
        return $this->state(fn (array $attributes) => [
            'blacklisted_at' => now(),
            'blacklist_reason' => $reason,
        ]);
    }
}
