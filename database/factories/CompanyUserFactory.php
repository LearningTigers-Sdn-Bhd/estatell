<?php

namespace Database\Factories;

use App\Enums\CompanyUserStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyUser>
 */
class CompanyUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'property_id' => fn (array $attributes): int => $this->propertyId($attributes['company_id']),
            'user_id' => User::factory()->companyUser(),
            'status' => CompanyUserStatus::Active,
            'accepted_at' => now(),
        ];
    }

    private function propertyId(int|string $companyId): int
    {
        return Company::query()->findOrFail($companyId)->property_id;
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => CompanyUserStatus::Pending,
            'accepted_at' => null,
            'last_sent_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'status' => CompanyUserStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
