<?php

namespace Database\Factories;

use App\Models\DailyRevenue;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyRevenue>
 */
class DailyRevenueFactory extends Factory
{
    /**
     * Define the model's default state: 480.00 on one code from four lines.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'business_date' => '2026-11-02',
            'transaction_code_id' => fn (array $attributes): int => TransactionCode::factory()->create(['property_id' => $attributes['property_id']])->id,
            'amount' => '480.00',
            'line_count' => 4,
        ];
    }
}
