<?php

namespace Database\Factories;

use App\Enums\PosTenderType;
use App\Models\PaymentMethod;
use App\Models\PosOutlet;
use App\Models\PosOutletPaymentMethod;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosOutletPaymentMethod>
 */
class PosOutletPaymentMethodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'payment_method_id' => fn (array $attributes): int => PaymentMethod::factory()->create(['property_id' => $attributes['property_id']])->id,
            'tender_type' => PosTenderType::Cash,
            'pulse_drawer' => true,
            'active' => true,
            'sort_order' => 0,
        ];
    }
}
