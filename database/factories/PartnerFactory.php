<?php

namespace Database\Factories;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Services\Platform\PartnerSignupCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Stays',
            'code_prefix' => fn (): string => $this->freshPrefix(),
            'signup_code' => fn (): string => app(PartnerSignupCode::class)->generate(),
            'status' => PartnerStatus::Active,
            'can_create_properties' => false,
        ];
    }

    /**
     * Give the partner its property code counter, as CreatePartner does, so IssuePropertyCode starts at 1101.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Partner $partner): void {
            DB::table('property_code_counters')->insertOrIgnore(['prefix' => $partner->code_prefix, 'last_number' => 1100]);
        });
    }

    public function canCreateProperties(): static
    {
        return $this->state(fn (array $attributes) => ['can_create_properties' => true]);
    }

    public function invited(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PartnerStatus::Invited]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PartnerStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    /**
     * A prefix that is not ES and is not used by a partner or a counter row yet.
     */
    private function freshPrefix(): string
    {
        do {
            $prefix = Str::upper(fake()->lexify('??'));
        } while ($prefix === 'ES' || DB::table('property_code_counters')->where('prefix', $prefix)->exists());

        return $prefix;
    }
}
