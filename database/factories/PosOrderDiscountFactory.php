<?php

namespace Database\Factories;

use App\Enums\PosDiscountType;
use App\Models\PosOrderDiscount;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOrderDiscount>
 */
class PosOrderDiscountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Bill discount',
            'discount_type' => PosDiscountType::VariablePercent,
            'value' => '10.00',
            'amount' => '0.00',
        ];
    }
}
