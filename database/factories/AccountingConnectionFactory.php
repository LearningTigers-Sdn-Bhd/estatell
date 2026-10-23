<?php

namespace Database\Factories;

use App\Enums\AccountingProvider;
use App\Models\AccountingConnection;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingConnection>
 */
class AccountingConnectionFactory extends Factory
{
    /**
     * An enabled QNE connection with a key and a guest ledger account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'provider' => AccountingProvider::Qne,
            'api_key' => fake()->uuid(),
            'guest_ledger_account_code' => '300-000',
            'enabled' => true,
        ];
    }
}
