<?php

namespace Database\Factories;

use App\Models\PosOutlet;
use App\Models\PosRegister;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosRegister>
 */
class PosRegisterFactory extends Factory
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
            'active' => true,
        ];
    }
}
