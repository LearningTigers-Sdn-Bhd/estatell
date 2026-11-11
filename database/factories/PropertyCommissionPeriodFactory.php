<?php

namespace Database\Factories;

use App\Enums\CommissionService;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyCommissionPeriod>
 */
class PropertyCommissionPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'service' => CommissionService::BookingEngine,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
        ];
    }
}
