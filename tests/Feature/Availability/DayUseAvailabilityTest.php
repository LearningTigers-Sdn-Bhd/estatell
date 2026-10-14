<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\UnitNight;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\DayUseAvailability;
use Carbon\CarbonImmutable;

/** @return list<string> Room numbers free for the hours. */
function freeDayUseRooms(array $setup, string $start = '10:00', string $end = '14:00', ?int $except = null): array
{
    return array_map(
        fn ($room) => $room->label,
        app(DayUseAvailability::class)->freeRooms($setup['roomType'], $setup['date'], $start, $end, $except),
    );
}

function overnightIn(Room $room, CarbonImmutable $arrival, int $nights, string $arrivalTime = '15:00', string $departureTime = '11:00'): ReservationUnit
{
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $room->property_id]))->withNights()->create([
        'room_type_id' => $room->room_type_id,
        'arrival_date' => $arrival->toDateString(),
        'departure_date' => $arrival->addDays($nights)->toDateString(),
        'arrival_time' => $arrivalTime,
        'departure_time' => $departureTime,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => $room->id]);

    return $unit;
}

test('every active room is free when nothing overlaps', function () {
    $setup = dayUseSetup(3);
    $setup['rooms'][2]->forceFill(['archived_at' => now()])->save();

    expect(freeDayUseRooms($setup))->toBe([$setup['rooms'][0]->number, $setup['rooms'][1]->number]);
});

test('an overnight guest leaving that morning blocks the room until their departure time', function () {
    $setup = dayUseSetup();
    overnightIn($setup['rooms'][0], $setup['date']->subDay(), 1, departureTime: '11:00');

    expect(freeDayUseRooms($setup, '10:00', '14:00'))->toBe([$setup['rooms'][1]->number])
        ->and(freeDayUseRooms($setup, '11:00', '14:00'))->toBe([$setup['rooms'][0]->number, $setup['rooms'][1]->number]);
});

test('an overnight guest arriving that day blocks the room from their arrival time', function () {
    $setup = dayUseSetup();
    overnightIn($setup['rooms'][0], $setup['date'], 2, arrivalTime: '15:00');

    expect(freeDayUseRooms($setup, '10:00', '14:00'))->toHaveCount(2)
        ->and(freeDayUseRooms($setup, '12:00', '16:00'))->toBe([$setup['rooms'][1]->number])
        ->and(freeDayUseRooms($setup, '08:00', '15:00'))->toHaveCount(2);
});

test('a guest staying through the date blocks the room all day', function () {
    $setup = dayUseSetup();
    overnightIn($setup['rooms'][1], $setup['date']->subDay(), 3);

    expect(freeDayUseRooms($setup, '08:00', '09:00'))->toBe([$setup['rooms'][0]->number]);
});

test('a cancelled overnight unit frees the room', function () {
    $setup = dayUseSetup();
    $unit = overnightIn($setup['rooms'][0], $setup['date'], 1);
    $unit->forceFill(['status' => 'cancelled'])->save();

    expect(freeDayUseRooms($setup, '12:00', '16:00'))->toHaveCount(2);
});

test('an open room block on the date, and only an open one, removes the room', function () {
    $setup = dayUseSetup();
    $block = RoomBlock::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['rooms'][0]->id, 'starts_on' => $setup['date']->toDateString(), 'ends_on' => $setup['date']->toDateString()]);

    expect(freeDayUseRooms($setup))->toBe([$setup['rooms'][1]->number]);

    $block->forceFill(['completed_at' => now()])->save();

    expect(freeDayUseRooms($setup))->toHaveCount(2);
});

test('another live day use removes the room only while the hours overlap, and a released one does not', function () {
    $setup = dayUseSetup();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->dayUse($setup['date']->toDateString(), '10:00', 4, $setup['rooms'][0]->id)->create(['room_type_id' => $setup['roomType']->id]);

    expect(freeDayUseRooms($setup, '12:00', '15:00'))->toBe([$setup['rooms'][1]->number])
        ->and(freeDayUseRooms($setup, '14:00', '16:00'))->toHaveCount(2)
        ->and(freeDayUseRooms($setup, '08:00', '10:00'))->toHaveCount(2)
        ->and(freeDayUseRooms($setup, '12:00', '15:00', $unit->id))->toHaveCount(2);

    $unit->dayUse->forceFill(['released_at' => now()])->save();

    expect(freeDayUseRooms($setup, '12:00', '15:00'))->toHaveCount(2);
});

test('isFree answers for one room and a bed room type has no day use rooms', function () {
    $setup = dayUseSetup();
    $availability = app(DayUseAvailability::class);

    expect($availability->isFree($setup['roomType'], $setup['rooms'][0]->id, $setup['date'], '10:00', '14:00'))->toBeTrue()
        ->and($availability->isFree($setup['roomType'], 999999, $setup['date'], '10:00', '14:00'))->toBeFalse();

    $setup['roomType']->forceFill(['sell_unit' => SellUnit::Bed])->save();

    expect($availability->freeRooms($setup['roomType'], $setup['date'], '10:00', '14:00'))->toBe([]);
});

test('a day use never changes the overnight availability', function () {
    $setup = dayUseSetup();
    ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->dayUse($setup['date']->toDateString(), '10:00', 4, $setup['rooms'][0]->id)->create(['room_type_id' => $setup['roomType']->id]);

    $night = app(AvailabilityService::class)->nights($setup['roomType'], $setup['date'], $setup['date']->addDay())[0];

    expect($night->available)->toBe(2)->and(StayType::DayUse->label())->toBe('Day use');
});
