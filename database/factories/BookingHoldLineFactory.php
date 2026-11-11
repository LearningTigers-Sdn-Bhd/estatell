<?php

namespace Database\Factories;

use App\Models\BookingHold;
use App\Models\BookingHoldLine;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingHoldLine>
 */
class BookingHoldLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_hold_id' => BookingHold::factory(),
            'room_type_id' => RoomType::factory(),
            'rate_plan_id' => RatePlan::factory(),
            'adults' => 2,
            'amount' => '200.00',
            'position' => 0,
        ];
    }
}
