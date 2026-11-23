<?php

namespace Database\Factories;

use App\Models\ChargeItem;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOutlet>
 */
class PosOutletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Main Restaurant',
            'code' => fake()->unique()->bothify('REST-###'),
            'default_charge_item_id' => fn (array $attributes): int => ChargeItem::factory()->create(['property_id' => $attributes['property_id']])->id,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
