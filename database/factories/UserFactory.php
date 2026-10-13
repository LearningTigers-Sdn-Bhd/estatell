<?php

namespace Database\Factories;

use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\DefaultRoleEnum;
use App\Models\Partner;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'is_admin' => false,
            'property_id' => Property::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the staff user is deactivated.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }

    /**
     * Indicate that the staff user signs in with a temp password and must set their own.
     */
    public function withTemporaryPassword(string $temporaryPassword = 'Kopi-7342-Meja'): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => $temporaryPassword,
            'temporary_password' => $temporaryPassword,
            'temporary_password_expires_at' => now()->addDays(14),
            'must_change_password' => true,
        ]);
    }

    /**
     * Indicate that the user is a platform admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'property_id' => null,
        ]);
    }

    /**
     * Indicate that the user is a partner user: it belongs to a partner and to no property.
     */
    public function partner(?Partner $partner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'partner_id' => $partner?->id ?? Partner::factory(),
            'property_id' => null,
        ]);
    }

    /**
     * Seed the property's default roles and give the user the Owner role.
     */
    public function owner(): static
    {
        return $this->withRole(DefaultRoleEnum::Owner);
    }

    /**
     * Seed the property's default roles and give the user one of them.
     */
    public function withRole(DefaultRoleEnum $defaultRole): static
    {
        return $this->afterCreating(function (User $user) use ($defaultRole): void {
            app(SeedDefaultRoles::class)($user->property);

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($user->property_id);

            $user->assignRole(Role::query()->where('property_id', $user->property_id)->where('default_role', $defaultRole)->firstOrFail());

            setPermissionsTeamId($previousTeamId);
        });
    }
}
