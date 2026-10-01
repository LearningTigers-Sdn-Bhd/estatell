<?php

namespace Database\Factories;

use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessDate>
 */
class BusinessDateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory()->live(),
            'date' => today(),
            'status' => BusinessDateStatus::Open,
            'opened_at' => now(),
        ];
    }

    public function auditing(): static
    {
        return $this->state(fn (array $attributes) => ['status' => BusinessDateStatus::Auditing]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BusinessDateStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
