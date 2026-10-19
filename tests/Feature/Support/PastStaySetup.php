<?php

use App\Enums\BusinessDateStatus;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;

require_once __DIR__.'/BookingSetup.php';

/**
 * `bookingSetup()` for a live property that went live on 2026-10-10 and trades on 2026-10-14 (the frozen day), with the
 * room revenue code and a 100.00 rate on every night from 2026-10-08 to 2026-10-19 (the booking setup rates start on the
 * 20th).
 *
 * @return array<string, mixed>
 */
function pastStaySetup(): array
{
    $setup = bookingSetup();
    $property = $setup['property'];
    $property->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-10']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14', 'status' => BusinessDateStatus::Open]);
    TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();

    for ($night = 0; $night < 12; $night++) {
        Rate::factory()->create(['rate_plan_id' => $setup['plan']->id, 'room_type_id' => $setup['roomType']->id, 'date' => now()->setDate(2026, 10, 8 + $night)->toDateString(), 'amount' => '100.00']);
    }

    return $setup;
}

/**
 * A Confirmed unit of the setup, with nights and room 101 on every night.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $attributes
 */
function pastStayUnit(array $setup, string $arrival, string $departure, array $attributes = []): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        ...$attributes,
    ]);
    $unit->nights()->update(['room_id' => $setup['rooms'][0]->id]);

    return $unit;
}
