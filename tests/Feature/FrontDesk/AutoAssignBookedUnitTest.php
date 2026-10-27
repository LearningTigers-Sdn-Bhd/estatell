<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Data\Reservations\NewBookingData;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use Database\Factories\BookingSourceFactory;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $data
 */
function autoAssignBooking(array $setup, array $data, bool $autoAssignRooms = true): BookingResult
{
    return app(CreateBooking::class)($setup['user'], $setup['property'], NewBookingData::from([
        'bookingSource' => BookingSourceFactory::directFor($setup['property']->id),
        'guests' => [['guestId' => $setup['guest']->id]],
        ...$data,
    ]), autoAssignRooms: $autoAssignRooms);
}

/**
 * @return array<string, mixed>
 */
function autoAssignRoomLine(array $setup, string $arrival = '2026-10-20', string $departure = '2026-10-22'): array
{
    return ['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => $arrival, 'departureDate' => $departure, 'adults' => 2];
}

/**
 * The rooms (or beds) of each unit's nights, by unit in booking order, with null for a night that has none.
 *
 * @return list<list<int|null>>
 */
function autoAssignedRooms(BookingResult $result, string $column = 'room_id'): array
{
    return $result->reservation->units()->orderBy('id')->get()
        ->map(fn (ReservationUnit $unit): array => $unit->nights()->orderBy('date')->pluck($column)->all())
        ->all();
}

function turnOnAutoAssign(array $setup): void
{
    $setup['property']->settings->forceFill(['auto_assign_rooms' => true])->save();
}

test('with the setting on every unit gets its own room or bed for the whole stay when the booking is saved', function () {
    $setup = bookingSetup();
    turnOnAutoAssign($setup);

    $result = autoAssignBooking($setup, ['units' => [
        autoAssignRoomLine($setup),
        autoAssignRoomLine($setup),
        ['roomTypeId' => $setup['dorm']->id, 'ratePlanId' => $setup['bedPlan']->id, 'arrivalDate' => '2026-10-20', 'departureDate' => '2026-10-22', 'adults' => 1, 'beds' => 2],
    ]]);

    $rooms = autoAssignedRooms($result);
    $beds = autoAssignedRooms($result, 'bed_id');

    expect($result->isRefused())->toBeFalse()
        ->and(array_map(fn (array $nights): int => count(array_unique($nights)), array_slice($rooms, 0, 2)))->toBe([1, 1])
        ->and(collect([$rooms[0][0], $rooms[1][0]])->sort()->values()->all())->toBe(collect($setup['rooms'])->pluck('id')->sort()->values()->all())
        ->and([$beds[2][0], $beds[3][0]])->each->not->toBeNull()
        ->and($beds[2][0])->not->toBe($beds[3][0]);
});

test('a staff pick is kept and only the units without one are assigned', function () {
    $setup = bookingSetup();
    turnOnAutoAssign($setup);

    $result = autoAssignBooking($setup, [
        'units' => [autoAssignRoomLine($setup), autoAssignRoomLine($setup)],
        'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][1]->id]],
    ]);

    expect(autoAssignedRooms($result))->toBe([
        [$setup['rooms'][1]->id, $setup['rooms'][1]->id],
        [$setup['rooms'][0]->id, $setup['rooms'][0]->id],
    ]);
});

test('a booking with no room free for the whole stay is still saved, with no room', function () {
    $setup = bookingSetup();
    turnOnAutoAssign($setup);
    autoAssignBooking($setup, ['units' => [autoAssignRoomLine($setup, '2026-10-20', '2026-10-21')], 'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][0]->id]]]);
    autoAssignBooking($setup, ['units' => [autoAssignRoomLine($setup, '2026-10-21', '2026-10-22')], 'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][1]->id]]]);

    $result = autoAssignBooking($setup, ['units' => [autoAssignRoomLine($setup)]]);

    expect($result->isRefused())->toBeFalse()
        ->and(autoAssignedRooms($result))->toBe([[null, null]]);
});

test('nothing is assigned with the setting off, or when the caller turns auto-assign off', function () {
    $setup = bookingSetup();

    autoAssignBooking($setup, ['units' => [autoAssignRoomLine($setup)]]);
    turnOnAutoAssign($setup);
    autoAssignBooking($setup, ['units' => [autoAssignRoomLine($setup)]], autoAssignRooms: false);

    expect(UnitNight::query()->whereNotNull('room_id')->count())->toBe(0);
});
