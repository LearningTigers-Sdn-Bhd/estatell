<?php

namespace Database\Factories;

use App\Enums\PosTableShape;
use App\Models\PosFloor;
use App\Models\PosTable;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosTable>
 */
class PosTableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_floor_id' => fn (array $attributes): int => PosFloor::factory()->create(['property_id' => $attributes['property_id']])->id,
            'code' => strtoupper(fake()->unique()->bothify('?##')),
            'label' => null,
            'seats' => 4,
            'shape' => PosTableShape::Square,
            'pos_x' => 0,
            'pos_y' => 0,
            'width' => 100,
            'height' => 100,
            'active' => true,
        ];
    }
}
