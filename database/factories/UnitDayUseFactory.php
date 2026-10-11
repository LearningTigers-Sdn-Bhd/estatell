<?php

namespace Database\Factories;

use App\Enums\StayType;
use App\Models\ReservationUnit;
use App\Models\UnitDayUse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitDayUse>
 */
class UnitDayUseFactory extends Factory
{
    /**
     * Define the model's default state: 10:00 to 14:00 on 2026-11-02, no room, priced at 95.00.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_unit_id' => ReservationUnit::factory()->state([
                'stay_type' => StayType::DayUse,
                'arrival_date' => '2026-11-02',
                'departure_date' => '2026-11-02',
                'arrival_time' => '10:00',
                'departure_time' => '14:00',
            ]),
            'date' => '2026-11-02',
            'room_id' => null,
            'starts_at' => '10:00',
            'ends_at' => '14:00',
            'hours' => 4,
            'amount' => '95.00',
        ];
    }
}
