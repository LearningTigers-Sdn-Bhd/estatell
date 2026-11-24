<?php

namespace Database\Factories;

use App\Models\PosCategory;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosCategory>
 */
class PosCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'transaction_code_id' => fn (array $attributes): int => TransactionCode::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => fake()->unique()->words(2, true),
            'color_token' => 'category-1',
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
