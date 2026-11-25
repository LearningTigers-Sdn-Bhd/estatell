<?php

namespace Database\Factories;

use App\Enums\PosAvailability;
use App\Models\PosItemVariant;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOutletItem>
 */
class PosOutletItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'pos_item_variant_id' => fn (array $attributes): int => PosItemVariant::factory()->create(['property_id' => $attributes['property_id']])->id,
            'availability' => PosAvailability::All,
        ];
    }
}
