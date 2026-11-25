<?php

namespace Database\Factories;

use App\Enums\PosPricingType;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosItemVariant>
 */
class PosItemVariantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_item_id' => fn (array $attributes): int => PosItem::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => 'Regular',
            'pricing_type' => PosPricingType::Fixed,
            'price' => '10.00',
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
