<?php

namespace Database\Factories;

use App\Enums\AccountingProvider;
use App\Enums\AccountingSyncStatus;
use App\Models\AccountingSync;
use App\Models\BusinessDate;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingSync>
 */
class AccountingSyncFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'business_date_id' => fn (array $attributes): int => BusinessDate::factory()->create([
                'property_id' => $attributes['property_id'],
            ])->id,
            'provider' => AccountingProvider::Qne,
            'status' => AccountingSyncStatus::Pending,
            'attempts' => 0,
        ];
    }
}
