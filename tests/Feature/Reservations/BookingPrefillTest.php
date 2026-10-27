<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $query
 */
function openNewBooking(array $setup, array $query): Assert
{
    $response = test()->actingAs($setup['user'])->get(route('reservations.new', ['property' => $setup['property']->code, ...$query]));

    return Assert::fromTestResponse($response);
}

test('a valid arrival and room prefill the form', function () {
    $setup = bookingSetup();
    $room = $setup['rooms'][1];

    openNewBooking($setup, ['arrival' => '2026-10-20', 'room' => $room->id])
        ->where('prefill', ['arrival' => '2026-10-20', 'roomTypeId' => $setup['roomType']->id, 'roomId' => $room->id, 'bedId' => null]);
});

test('a valid bed prefills its room type and the bed', function () {
    $setup = bookingSetup();

    openNewBooking($setup, ['arrival' => '2026-10-10', 'bed' => $setup['beds'][2]->id])
        ->where('prefill', ['arrival' => '2026-10-10', 'roomTypeId' => $setup['dorm']->id, 'roomId' => null, 'bedId' => $setup['beds'][2]->id]);
});

test('no query leaves the form blank', function () {
    $setup = bookingSetup();

    openNewBooking($setup, [])
        ->where('prefill', ['arrival' => null, 'roomTypeId' => null, 'roomId' => null, 'bedId' => null]);
});

test('a bad arrival is ignored and the valid room still applies', function (string $arrival) {
    $setup = bookingSetup();
    $room = $setup['rooms'][0];

    openNewBooking($setup, ['arrival' => $arrival, 'room' => $room->id])
        ->where('prefill.arrival', null)
        ->where('prefill.roomId', $room->id);
})->with(['before the business date' => '2026-10-09', 'not a date' => 'tomorrow', 'loose format' => '2026-10-2', 'too far ahead' => '2031-10-10']);

test('a room of another property is ignored', function () {
    $setup = bookingSetup();
    $foreign = Room::factory()->for(Property::factory()->create())->create();

    openNewBooking($setup, ['arrival' => '2026-10-20', 'room' => $foreign->id])
        ->where('prefill', ['arrival' => '2026-10-20', 'roomTypeId' => null, 'roomId' => null, 'bedId' => null]);
});

test('a bed of another property is ignored', function () {
    $setup = bookingSetup();
    $otherProperty = Property::factory()->create();
    $dorm = RoomType::factory()->bed()->for($otherProperty)->create();
    $bed = Bed::factory()->create(['room_id' => Room::factory()->for($otherProperty)->create(['room_type_id' => $dorm->id])->id]);

    openNewBooking($setup, ['bed' => $bed->id])->where('prefill.bedId', null)->where('prefill.roomTypeId', null);
});

test('an archived room or bed is ignored', function () {
    $setup = bookingSetup();
    $setup['rooms'][0]->update(['archived_at' => now()]);
    $setup['beds'][0]->update(['archived_at' => now()]);

    openNewBooking($setup, ['room' => $setup['rooms'][0]->id])->where('prefill.roomId', null);
    openNewBooking($setup, ['bed' => $setup['beds'][0]->id])->where('prefill.bedId', null);
});

test('a room that sells beds is not taken as room', function () {
    $setup = bookingSetup();
    $dormRoomId = $setup['beds'][0]->room_id;

    openNewBooking($setup, ['room' => $dormRoomId])
        ->where('prefill.roomId', null)
        ->where('prefill.roomTypeId', null);
});

test('only the New booking form reads the prefill', function () {
    $setup = bookingSetup();

    $response = $this->actingAs($setup['user'])
        ->get(route('reservations.walk-in', ['property' => $setup['property']->code, 'arrival' => '2026-10-20', 'room' => $setup['rooms'][0]->id]));

    Assert::fromTestResponse($response)->where('prefill', null);
});
