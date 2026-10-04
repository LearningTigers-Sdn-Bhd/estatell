<?php

namespace Database\Factories;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArInvoice>
 */
class ArInvoiceFactory extends Factory
{
    /**
     * Define the model's default state: an Open invoice of 500.00 due in 30 days, with no folio.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'company_id' => fn (array $attributes): int => Company::factory()->create(['property_id' => $attributes['property_id']])->id,
            'type' => ArInvoiceType::Invoice,
            'number' => fn (): string => 'INV-'.fake()->unique()->numerify('######'),
            'issued_on' => '2026-10-04',
            'due_on' => '2026-11-03',
            'amount' => '500.00',
            'status' => ArInvoiceStatus::Open,
            'created_by' => fn (array $attributes): int => User::factory()->create(['property_id' => $attributes['property_id']])->id,
        ];
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ArInvoiceStatus::Settled]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ArInvoiceStatus::Void]);
    }
}
