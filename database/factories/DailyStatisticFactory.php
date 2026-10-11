<?php

namespace Database\Factories;

use App\Models\DailyStatistic;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyStatistic>
 */
class DailyStatisticFactory extends Factory
{
    /**
     * Define the model's default state: ten rooms, four sold at 120.00.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'business_date' => '2026-11-02',
            'rooms_available' => 10,
            'rooms_sold' => 4,
            'beds_available' => 0,
            'beds_sold' => 0,
            'occupancy' => '40.00',
            'adr' => '120.00',
            'revpar' => '48.00',
            'arrivals' => 2,
            'departures' => 1,
            'no_shows' => 0,
            'in_house_guests' => 6,
            'day_use_sold' => 0,
            'day_use_revenue' => '0.00',
        ];
    }
}
