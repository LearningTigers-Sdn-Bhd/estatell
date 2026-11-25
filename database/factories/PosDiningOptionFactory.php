<?php

namespace Database\Factories;

use App\Enums\PosDiningKind;
use App\Models\PosDiningOption;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosDiningOption>
 */
class PosDiningOptionFactory extends Factory
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
            'kind' => PosDiningKind::DineIn,
            'apply_service_charge' => true,
            'service_charge_rate' => '10.00',
            'service_charge_taxable' => false,
            'packaging_fee' => '0.00',
            'is_default' => false,
            'active' => true,
        ];
    }
}
