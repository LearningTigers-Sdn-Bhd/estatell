<?php

namespace Database\Factories;

use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\StaffInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffInvitation>
 */
class StaffInvitationFactory extends Factory
{
    /**
     * Define the model's default state. The role is the property's Front desk role.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role_id' => function (array $attributes): int {
                $property = Property::query()->findOrFail($attributes['property_id']);
                app(SeedDefaultRoles::class)($property);

                return Role::query()->where('property_id', $property->id)->where('default_role', DefaultRoleEnum::FrontDesk)->value('id');
            },
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
