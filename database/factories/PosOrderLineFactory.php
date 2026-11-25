<?php

namespace Database\Factories;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOrderLine>
 */
class PosOrderLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_order_id' => fn (array $attributes): int => PosOrder::factory()->create(['property_id' => $attributes['property_id']])->id,
            'transaction_code_id' => fn (array $attributes): int => TransactionCode::factory()->create(['property_id' => $attributes['property_id']])->id,
            'line_kind' => PosOrderLineKind::Item,
            'item_name' => fake()->words(2, true),
            'variant_name' => 'Regular',
            'quantity' => '1.000',
            'unit_price' => '10.00',
            'kitchen_status' => PosKitchenStatus::Draft,
        ];
    }
}
