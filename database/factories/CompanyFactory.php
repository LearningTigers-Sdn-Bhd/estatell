<?php

namespace Database\Factories;

use App\Enums\CompanyMarket;
use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
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
            'name' => fake()->unique()->company(),
            'type' => CompanyType::Corporate,
            'contact_email' => fake()->companyEmail(),
            'credit_limit' => null,
            'payment_terms_days' => 30,
            'direct_bill_enabled' => true,
            'status' => CompanyStatus::Active,
        ];
    }

    public function inMarket(CompanyMarket $market): static
    {
        return $this->state(fn (array $attributes) => ['market' => $market]);
    }

    public function travelAgent(): static
    {
        return $this->state(fn (array $attributes) => ['type' => CompanyType::TravelAgent]);
    }

    /**
     * The company has asked to manage its own tax numbers and billing address in the portal.
     */
    public function managedByCompany(): static
    {
        return $this->state(fn (array $attributes) => ['profile_managed_by_company' => true]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['status' => CompanyStatus::Suspended]);
    }
}
