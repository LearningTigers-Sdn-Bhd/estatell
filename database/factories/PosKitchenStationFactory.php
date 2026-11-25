<?php

namespace Database\Factories;

use App\Enums\PosStationMode;
use App\Models\PosKitchenStation;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosKitchenStation>
 */
class PosKitchenStationFactory extends Factory
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
            'mode' => PosStationMode::Kds,
            'use_internal_name' => false,
            'warn_after_minutes' => 10,
            'alert_after_minutes' => 20,
            'filter_dining_kinds' => null,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
