<?php

namespace Database\Factories;

use App\Models\PosMenuSchedule;
use App\Models\PosOutlet;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosMenuSchedule>
 */
class PosMenuScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'name' => 'Breakfast',
            'days_of_week' => 127,
            'start_time' => '06:00:00',
            'end_time' => '11:00:00',
            'active' => true,
        ];
    }
}
