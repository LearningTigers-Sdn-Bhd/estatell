<?php

namespace Database\Factories;

use App\Models\PosFloor;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosFloor>
 */
class PosFloorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
