<?php

namespace Database\Factories;

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionCode>
 */
class TransactionCodeFactory extends Factory
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
            'system_key' => null,
            'code' => fake()->unique()->bothify('CODE_####'),
            'name' => fake()->unique()->words(2, true),
            'kind' => TransactionKind::Charge,
            'category' => TransactionCategory::Other,
            'gl_account_code' => null,
            'is_system' => false,
            'active' => true,
        ];
    }

    public function system(SystemTransactionCode $systemCode = SystemTransactionCode::RoomRevenue): static
    {
        return $this->state(fn (array $attributes) => [
            'system_key' => $systemCode->value,
            'code' => $systemCode->code(),
            'name' => $systemCode->label(),
            'kind' => $systemCode->kind(),
            'category' => $systemCode->category(),
            'is_system' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
