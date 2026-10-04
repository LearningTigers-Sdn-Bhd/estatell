<?php

namespace Database\Factories;

use App\Models\ArPayment;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArPayment>
 */
class ArPaymentFactory extends Factory
{
    /**
     * Define the model's default state: a 500.00 payment received on 2026-10-04 with no allocations.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'company_id' => fn (array $attributes): int => Company::factory()->create(['property_id' => $attributes['property_id']])->id,
            'payment_method_id' => fn (array $attributes): int => PaymentMethod::factory()->create(['property_id' => $attributes['property_id']])->id,
            'amount' => '500.00',
            'received_on' => '2026-10-04',
            'receipt_number' => fn (): string => 'RCP-'.fake()->unique()->numerify('######'),
            'idempotency_key' => fn (): string => fake()->uuid(),
            'received_by' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'voided_at' => now(),
            'voided_by' => $attributes['received_by'],
        ]);
    }
}
