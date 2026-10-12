<?php

namespace Database\Factories;

use App\Enums\AgeBandPricing;
use App\Models\ChargeItem;
use App\Models\ChargeItemChildPrice;
use App\Models\PropertyAgeGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargeItemChildPrice>
 */
class ChargeItemChildPriceFactory extends Factory
{
    /**
     * Define the model's default state: children pay the adult price (Percent 100).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charge_item_id' => ChargeItem::factory(),
            'property_age_group_id' => fn (array $attributes): int => PropertyAgeGroup::factory()->create([
                'property_id' => ChargeItem::query()->findOrFail($attributes['charge_item_id'])->property_id,
            ])->id,
            'pricing' => AgeBandPricing::Percent,
            'value' => '100.00',
        ];
    }

    public function free(): static
    {
        return $this->state(fn (array $attributes) => ['pricing' => AgeBandPricing::Free, 'value' => null]);
    }

    public function fixed(string $value): static
    {
        return $this->state(fn (array $attributes) => ['pricing' => AgeBandPricing::Fixed, 'value' => $value]);
    }

    public function percent(string $value): static
    {
        return $this->state(fn (array $attributes) => ['pricing' => AgeBandPricing::Percent, 'value' => $value]);
    }
}
