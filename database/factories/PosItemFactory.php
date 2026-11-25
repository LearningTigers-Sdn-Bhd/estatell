<?php

namespace Database\Factories;

use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosItem>
 */
class PosItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_category_id' => fn (array $attributes): int => PosCategory::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => fake()->unique()->words(2, true),
            'price_includes_tax' => false,
            'service_charge_exempt' => false,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
