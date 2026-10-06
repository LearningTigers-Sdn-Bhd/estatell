<?php

namespace Database\Factories;

use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
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
                'kind' => TransactionKind::Tax,
                'category' => TransactionCategory::Tax,
            ])->id,
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->bothify('TAX_####'),
            'rate_type' => TaxRateType::Percent,
            'rate' => '8.00',
            'is_inclusive' => false,
            'foreign_guests_only' => false,
            'collect_at_check_in' => false,
            'registration_number' => null,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['enabled' => false]);
    }
}
