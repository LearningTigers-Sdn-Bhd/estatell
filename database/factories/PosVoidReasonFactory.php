<?php

namespace Database\Factories;

use App\Models\PosVoidReason;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosVoidReason>
 */
class PosVoidReasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'label' => fake()->unique()->words(2, true),
            'requires_manager_pin' => true,
            'default_return_stock' => false,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
