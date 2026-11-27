<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\TransferDriver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An own-staff driver by default.
 *
 * @extends Factory<TransferDriver>
 */
class TransferDriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->firstName().' '.fake()->numberBetween(1, 999),
            'phone' => null,
            'subcontractor' => null,
            'active' => true,
        ];
    }
}
