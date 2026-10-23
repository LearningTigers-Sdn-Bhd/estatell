<?php

namespace Database\Factories;

use App\Enums\AccountingProvider;
use App\Models\AccountingCustomer;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingCustomer>
 */
class AccountingCustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'property_id' => fn (array $attributes): int => Company::query()->findOrFail((int) $attributes['company_id'])->property_id,
            'provider' => AccountingProvider::Qne,
            'external_id' => (string) fake()->unique()->numberBetween(100, 99999),
        ];
    }
}
