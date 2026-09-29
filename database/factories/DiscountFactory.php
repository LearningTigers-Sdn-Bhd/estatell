<?php

namespace Database\Factories;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Discount;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'transaction_code_id' => fn (array $attributes) => TransactionCode::factory()->create([
                'property_id' => $attributes['property_id'],
                'name' => $attributes['name'],
                'kind' => TransactionKind::Adjustment,
                'category' => TransactionCategory::Discount,
                'active' => $attributes['active'],
            ])->id,
            'type' => DiscountType::Percent,
            'value' => '10.00',
            'allow_override' => false,
            'scope' => DiscountScope::RoomOnly,
            'position' => 1,
            'active' => true,
        ];
    }

    public function fixed(string $value = '50.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DiscountType::Fixed,
            'value' => $value,
            'allow_override' => true,
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DiscountType::Open,
            'value' => null,
            'allow_override' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
