<?php

namespace Database\Factories;

use App\Models\PosOrderLineModifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOrderLineModifier>
 */
class PosOrderLineModifierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'option_name' => fake()->word(),
            'price_delta' => '0.00',
            'charged_price' => '0.00',
            'quantity' => 1,
        ];
    }
}
