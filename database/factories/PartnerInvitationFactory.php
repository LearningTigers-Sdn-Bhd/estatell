<?php

namespace Database\Factories;

use App\Models\Partner;
use App\Models\PartnerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerInvitation>
 */
class PartnerInvitationFactory extends Factory
{
    /**
     * Define the model's default state. The partner is Invited, as InvitePartner leaves it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory()->invited(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', 'token-'.fake()->unique()->uuid()),
            'expires_at' => now()->addDays(7),
            'last_sent_at' => now(),
        ];
    }

    /**
     * Use a known plain token, for tests that open the invitation link.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => ['token_hash' => hash('sha256', $token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['accepted_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['revoked_at' => now()]);
    }
}
