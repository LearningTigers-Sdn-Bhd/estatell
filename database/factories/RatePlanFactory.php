<?php

namespace Database\Factories;

use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\RatePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlan>
 */
class RatePlanFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'pricing_basis' => PricingBasis::PerRoom,
        ];
    }

    public function perGuest(): static
    {
        return $this->state(fn (array $attributes) => ['pricing_basis' => PricingBasis::PerGuest]);
    }

    public function package(PackageUnit $unit = PackageUnit::PerGuest, int $nights = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_basis' => PricingBasis::Package,
            'package_unit' => $unit,
            'fixed_nights' => $nights,
        ]);
    }

    public function perBed(): static
    {
        return $this->state(fn (array $attributes) => ['pricing_basis' => PricingBasis::PerBed]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived_at' => now()]);
    }
}
