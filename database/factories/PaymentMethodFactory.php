<?php

namespace Database\Factories;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'transaction_code_id' => fn (array $attributes) => TransactionCode::factory()->create([
                'property_id' => $attributes['property_id'],
                'kind' => TransactionKind::Payment,
                'category' => TransactionCategory::Card,
            ])->id,
            'name' => fake()->unique()->words(2, true),
            'type' => PaymentMethodType::Card,
            'surcharge_type' => SurchargeType::None,
            'surcharge_value' => null,
            'surcharge_charge_item_id' => null,
            'requires_reference' => true,
            'position' => 1,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
