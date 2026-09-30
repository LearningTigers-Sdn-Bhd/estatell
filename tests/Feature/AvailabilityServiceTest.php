<?php

use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use Illuminate\Support\Facades\Date;

/** @return array{0: Property, 1: RoomType} */
function availabilitySetup(int $rooms = 3): array
{
    $property = Property::factory()->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count($rooms)->for($property)->create(['room_type_id' => $roomType->id]);

    return [$property, $roomType];
}

/**
 * A Bed room type with rooms of the given bed counts.
 *
 * @param  list<int>  $bedsPerRoom
 * @return array{0: Property, 1: RoomType, 2: list<Room>}
 */
function dormSetup(array $bedsPerRoom): array
{
    $property = Property::factory()->create();
    $roomType = RoomType::factory()->for($property)->bed()->create();
    $rooms = [];

    foreach ($bedsPerRoom as $count) {
        $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
        Bed::factory()->count($count)->create(['room_id' => $room->id]);
        $rooms[] = $room;
    }

    return [$property, $roomType, $rooms];
}

/** @return list<int> */
function availableByNight(RoomType $roomType, string $arrival, string $departure): array
{
    return array_map(
        fn (NightAvailability $night): int => $night->available,
        app(AvailabilityService::class)->nights($roomType, Date::parse($arrival), Date::parse($departure)),
    );
}

function blockRoom(Room $room, string $from, string $to, ?Bed $bed = null, array $attributes = []): RoomBlock
{
    return RoomBlock::factory()->create([
        'room_id' => $room->id,
        'bed_id' => $bed?->id,
        'property_id' => $room->property_id,
        'starts_on' => $from,
        'ends_on' => $to,
        ...$attributes,
    ]);
}

test('a room type with no blocks has all its active rooms free on every night', function () {
    [$property, $roomType] = availabilitySetup(3);
    Room::factory()->archived()->for($property)->create(['room_type_id' => $roomType->id]);

    $nights = app(AvailabilityService::class)->nights($roomType, Date::parse('2026-11-02'), Date::parse('2026-11-05'));

    expect($nights)->toHaveCount(3)
        ->and($nights[0]->date->toDateString())->toBe('2026-11-02')
        ->and($nights[2]->date->toDateString())->toBe('2026-11-04')
        ->and($nights[0]->total)->toBe(3)
        ->and($nights[0]->blocked)->toBe(0)
        ->and($nights[0]->booked)->toBe(0)
        ->and(array_map(fn ($night) => $night->available, $nights))->toBe([3, 3, 3]);
});

test('the departure date is not a night', function () {
    [, $roomType] = availabilitySetup();

    expect(availableByNight($roomType, '2026-11-02', '2026-11-03'))->toBe([3])
        ->and(availableByNight($roomType, '2026-11-02', '2026-11-02'))->toBe([])
        ->and(availableByNight($roomType, '2026-11-03', '2026-11-02'))->toBe([])
        ->and(app(AvailabilityService::class)->availableForStay($roomType, Date::parse('2026-11-03'), Date::parse('2026-11-02')))->toBe(0);
});

test('an open room block takes its room off every blocked night, both ends included', function () {
    [$property, $roomType] = availabilitySetup(3);
    $room = Room::query()->where('room_type_id', $roomType->id)->first();
    blockRoom($room, '2026-11-03', '2026-11-04');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-07'))->toBe([3, 2, 2, 3, 3]);
});

test('a block that ends or starts outside the range still counts on the nights inside it', function () {
    [, $roomType] = availabilitySetup(2);
    $room = Room::query()->where('room_type_id', $roomType->id)->first();
    blockRoom($room, '2026-10-20', '2026-11-03');
    blockRoom($room, '2026-11-06', '2026-12-31');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-08'))->toBe([1, 1, 2, 2, 1, 1]);
});

test('a completed block, a block outside the range and a block on another room type do not count', function () {
    [$property, $roomType] = availabilitySetup(2);
    $room = Room::query()->where('room_type_id', $roomType->id)->first();
    $otherType = RoomType::factory()->for($property)->create();
    $otherRoom = Room::factory()->for($property)->create(['room_type_id' => $otherType->id]);
    blockRoom($room, '2026-11-02', '2026-11-04', attributes: ['completed_at' => now()]);
    blockRoom($room, '2026-12-01', '2026-12-02');
    blockRoom($otherRoom, '2026-11-02', '2026-11-04');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-05'))->toBe([2, 2, 2]);
});

test('a room blocked twice on one night counts once, and two rooms count twice', function () {
    [, $roomType] = availabilitySetup(3);
    [$first, $second] = Room::query()->where('room_type_id', $roomType->id)->orderBy('id')->get();
    blockRoom($first, '2026-11-02', '2026-11-03');
    blockRoom($first, '2026-11-03', '2026-11-04');
    blockRoom($second, '2026-11-04', '2026-11-04');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-05'))->toBe([2, 2, 1]);
});

test('availability never goes below zero', function () {
    [, $roomType] = availabilitySetup(1);
    $room = Room::query()->where('room_type_id', $roomType->id)->first();
    blockRoom($room, '2026-11-02', '2026-11-02');

    $night = app(AvailabilityService::class)->nights($roomType, Date::parse('2026-11-02'), Date::parse('2026-11-03'))[0];

    expect($night->available)->toBe(0)->and($night->blocked)->toBe(1);
});

test('the stay is as free as its fullest night', function () {
    [, $roomType] = availabilitySetup(3);
    [$first, $second] = Room::query()->where('room_type_id', $roomType->id)->orderBy('id')->get();
    blockRoom($first, '2026-11-03', '2026-11-03');
    blockRoom($second, '2026-11-03', '2026-11-03');

    $service = app(AvailabilityService::class);

    expect($service->availableForStay($roomType, Date::parse('2026-11-02'), Date::parse('2026-11-05')))->toBe(1)
        ->and($service->availableForStay($roomType, Date::parse('2026-11-04'), Date::parse('2026-11-06')))->toBe(3);
});

test('another property\'s rooms and blocks never count', function () {
    [$property, $roomType] = availabilitySetup(2);
    [$otherProperty, $otherType] = availabilitySetup(5);
    blockRoom(Room::query()->where('room_type_id', $otherType->id)->first(), '2026-11-02', '2026-11-04');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-04'))->toBe([2, 2])
        ->and(availableByNight($otherType, '2026-11-02', '2026-11-04'))->toBe([4, 4]);
});

test('a bed room type counts the active beds of its active rooms', function () {
    [$property, $roomType, [$dorm, $small]] = dormSetup([4, 2]);
    Bed::factory()->archived()->create(['room_id' => $dorm->id]);
    $archivedRoom = Room::factory()->archived()->for($property)->create(['room_type_id' => $roomType->id]);
    Bed::factory()->count(3)->create(['room_id' => $archivedRoom->id]);

    $night = app(AvailabilityService::class)->nights($roomType, Date::parse('2026-11-02'), Date::parse('2026-11-03'))[0];

    expect($night->total)->toBe(6)->and($night->available)->toBe(6);
});

test('a whole-room block removes every active bed of the room', function () {
    [, $roomType, [$dorm, $small]] = dormSetup([4, 2]);
    Bed::factory()->archived()->create(['room_id' => $dorm->id]);
    blockRoom($dorm, '2026-11-03', '2026-11-03');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-05'))->toBe([6, 2, 6]);
});

test('a bed block removes that bed only', function () {
    [, $roomType, [$dorm]] = dormSetup([4]);
    $bed = Bed::query()->where('room_id', $dorm->id)->first();
    blockRoom($dorm, '2026-11-02', '2026-11-03', $bed);

    expect(availableByNight($roomType, '2026-11-02', '2026-11-05'))->toBe([3, 3, 4]);
});

test('a bed block and a whole-room block on the same night count each bed once', function () {
    [, $roomType, [$dorm]] = dormSetup([4]);
    $bed = Bed::query()->where('room_id', $dorm->id)->first();
    blockRoom($dorm, '2026-11-02', '2026-11-02', $bed);
    blockRoom($dorm, '2026-11-02', '2026-11-02');

    expect(availableByNight($roomType, '2026-11-02', '2026-11-03'))->toBe([0]);
});

test('a block on an archived bed takes nothing off', function () {
    [, $roomType, [$dorm]] = dormSetup([3]);
    $archived = Bed::factory()->archived()->create(['room_id' => $dorm->id]);
    blockRoom($dorm, '2026-11-02', '2026-11-02', $archived);

    expect(availableByNight($roomType, '2026-11-02', '2026-11-03'))->toBe([3]);
});

test('a bed block on a room type that sells rooms is ignored', function () {
    [, $roomType] = availabilitySetup(2);
    $room = Room::query()->where('room_type_id', $roomType->id)->first();
    $bed = Bed::factory()->create(['room_id' => $room->id]);
    blockRoom($room, '2026-11-02', '2026-11-02', $bed);

    expect($roomType->sell_unit)->toBe(SellUnit::Room)
        ->and(availableByNight($roomType, '2026-11-02', '2026-11-03'))->toBe([2]);
});

test('availability runs in a fixed number of queries however many rooms and blocks there are', function () {
    [, $roomType] = availabilitySetup(8);
    Room::query()->where('room_type_id', $roomType->id)->get()->each(fn (Room $room) => blockRoom($room, '2026-11-03', '2026-11-05'));

    DB::enableQueryLog();
    app(AvailabilityService::class)->nights($roomType, Date::parse('2026-11-01'), Date::parse('2026-11-30'));

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});
