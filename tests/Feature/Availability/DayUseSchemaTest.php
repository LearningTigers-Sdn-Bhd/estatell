<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitDayUse;
use Illuminate\Support\Facades\DB;

function dayUseUnitAt(Room $room, string $date, string $start, int $hours, ?string $releasedAt = null): UnitDayUse
{
    $reservation = Reservation::factory()->create(['property_id' => $room->property_id]);
    $unit = ReservationUnit::factory()->for($reservation)->dayUse($date, $start, $hours, $room->id)->create();

    return tap($unit->dayUse, fn (UnitDayUse $dayUse) => $releasedAt === null ? null : $dayUse->forceFill(['released_at' => $releasedAt])->save());
}

test('stay type checks: overnight needs departure after arrival and day use the same day', function () {
    $reservation = Reservation::factory()->create();

    expectDatabaseRefuses(fn () => ReservationUnit::factory()->for($reservation)->create(['stay_type' => 'day_use', 'arrival_date' => '2026-11-02', 'departure_date' => '2026-11-03']), 'reservation_units_dates_check');
    expectDatabaseRefuses(fn () => ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-11-02', 'departure_date' => '2026-11-02']), 'reservation_units_dates_check');
    $existing = ReservationUnit::factory()->for($reservation)->create();
    expectDatabaseRefuses(fn () => DB::table('reservation_units')->where('id', $existing->id)->update(['stay_type' => 'weekend']), 'violates check constraint');

    expect(ReservationUnit::factory()->for($reservation)->dayUse()->create()->stay_type->value)->toBe('day_use');
});

test('unit_day_uses checks: ends after starts, hours match the times and run from 1 to 24', function () {
    $insert = fn (array $values) => fn () => DB::table('unit_day_uses')->insert([
        'reservation_unit_id' => ReservationUnit::factory()->create(['stay_type' => 'day_use', 'arrival_date' => '2026-11-02', 'departure_date' => '2026-11-02'])->id,
        'date' => '2026-11-02', 'starts_at' => '10:00', 'ends_at' => '14:00', 'hours' => 4, 'amount' => '95.00', 'created_at' => now(), 'updated_at' => now(),
        ...$values,
    ]);

    expectDatabaseRefuses($insert(['ends_at' => '10:00', 'hours' => 1]), 'violates check constraint');
    expectDatabaseRefuses($insert(['hours' => 3]), 'unit_day_uses_hours_match_check');
    expectDatabaseRefuses($insert(['hours' => 0, 'ends_at' => '10:00']), 'unit_day_uses_');
    expectDatabaseRefuses($insert(['amount' => '-1.00']), 'unit_day_uses_amount_check');
    expectDatabaseRefuses($insert(['override_amount' => '-1.00']), 'unit_day_uses_override_amount_check');
    expectDatabaseRefuses($insert(['override_amount' => '80.00']), 'unit_day_uses_override_amount_check');
    expect($insert(['override_amount' => '0.00', 'amount' => '0.00'])())->toBeTrue();
    expect($insert(['starts_at' => '09:30', 'ends_at' => '14:30', 'hours' => 5])())->toBeTrue();
});

test('two live day uses cannot overlap in one room and the database says so', function () {
    $room = Room::factory()->create();
    dayUseUnitAt($room, '2026-11-02', '10:00', 4);

    expectDatabaseRefuses(fn () => dayUseUnitAt($room, '2026-11-02', '12:00', 4), 'unit_day_uses_no_overlap');
});

test('back to back blocks, other rooms, other dates, no room and released blocks do not conflict', function () {
    $room = Room::factory()->create();
    $first = dayUseUnitAt($room, '2026-11-02', '10:00', 4);

    expect(dayUseUnitAt($room, '2026-11-02', '14:00', 2)->exists)->toBeTrue()
        ->and(dayUseUnitAt($room, '2026-11-02', '08:00', 2)->exists)->toBeTrue()
        ->and(dayUseUnitAt(Room::factory()->create(), '2026-11-02', '10:00', 4)->exists)->toBeTrue()
        ->and(dayUseUnitAt($room, '2026-11-03', '10:00', 4)->exists)->toBeTrue();

    $first->forceFill(['released_at' => now()])->save();

    expect(dayUseUnitAt($room, '2026-11-02', '11:00', 2)->exists)->toBeTrue();

    $unit = ReservationUnit::factory()->dayUse('2026-11-02', '10:00', 4, null)->create();
    $other = ReservationUnit::factory()->dayUse('2026-11-02', '10:00', 4, null)->create();

    expect($unit->dayUse->room_id)->toBeNull()->and($other->dayUse->room_id)->toBeNull();
});
