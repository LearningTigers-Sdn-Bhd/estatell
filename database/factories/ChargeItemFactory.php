<?php

namespace Database\Factories;

use App\Enums\ChargingUnit;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargeItem>
 */
class ChargeItemFactory extends Factory
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
            'transaction_code_id' => fn (array $attributes) => TransactionCode::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'default_amount' => fake()->randomFloat(2, 1, 500),
            'charging_unit' => ChargingUnit::PerItem,
            'allow_override' => true,
            'position' => 1,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
