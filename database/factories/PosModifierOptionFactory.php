<?php

namespace Database\Factories;

use App\Enums\PosAvailability;
use App\Models\PosModifierGroup;
use App\Models\PosModifierOption;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosModifierOption>
 */
class PosModifierOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_modifier_group_id' => fn (array $attributes): int => PosModifierGroup::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => fake()->unique()->word(),
            'price_delta' => '0.00',
            'availability' => PosAvailability::All,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
