<?php

use App\Actions\Reservations\AssignBed;
use App\Actions\Reservations\AssignmentResult;
use App\Actions\Reservations\AssignRoom;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\UnitNight;
use Spatie\Activitylog\Models\Activity;

test('a room is assigned to every night of the unit and the assignment is logged', function () {
    $setup = assignSetup();

    $result = app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0]);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['unit']->nights()->pluck('room_id')->unique()->all())->toBe([$setup['rooms'][0]->id])
        ->and($setup['unit']->nights()->whereNotNull('bed_id')->count())->toBe(0);

    $activity = Activity::query()->where('event', 'reservation.room_assigned')->sole();

    expect($activity->causer_id)->toBe($setup['user']->id)
        ->and($activity->subject_id)->toBe($setup['unit']->reservation_id)
        ->and($activity->properties->all())->toBe(['property_id' => $setup['property']->id, 'reservation_unit_id' => $setup['unit']->id, 'room_id' => $setup['rooms'][0]->id]);
});

test('a unit with a room is moved to another room, and may keep its own room', function () {
    $setup = assignSetup();
    app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0]);

    expect(app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0])->isRefused())->toBeFalse();

    app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][1]);

    expect($setup['unit']->nights()->pluck('room_id')->unique()->all())->toBe([$setup['rooms'][1]->id]);
});

test('a room taken on one night by another unit is refused with that night and nothing is written', function () {
    $setup = assignSetup();
    $other = assignUnit($setup['property'], $setup['roomType'], ['arrival_date' => '2026-10-21', 'departure_date' => '2026-10-23']);
    app(AssignRoom::class)($setup['user'], $other, $setup['rooms'][0]);
    Activity::query()->delete();

    $result = app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0]);

    expect($result->refusal)->toBe(AssignmentResult::REFUSAL_ROOM_NOT_FREE)
        ->and($result->date->toDateString())->toBe('2026-10-21')
        ->and($setup['unit']->nights()->whereNotNull('room_id')->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('a room frees up when the stay that held it ends before', function () {
    $setup = assignSetup();
    $earlier = assignUnit($setup['property'], $setup['roomType'], ['arrival_date' => '2026-10-18', 'departure_date' => '2026-10-20']);
    UnitNight::query()->where('reservation_unit_id', $earlier->id)->update(['room_id' => $setup['rooms'][0]->id]);

    expect(app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0])->isRefused())->toBeFalse();
});

test('a blocked room is refused', function () {
    $setup = assignSetup();
    RoomBlock::factory()->create(['room_id' => $setup['rooms'][0]->id, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21']);

    $result = app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0]);

    expect($result->refusal)->toBe(AssignmentResult::REFUSAL_ROOM_NOT_FREE)
        ->and($result->date->toDateString())->toBe('2026-10-21');
});

test('a room that cannot be sold to the unit is refused as not found', function (string $case) {
    $setup = assignSetup();
    $setup['rooms'][0]->forceFill(['archived_at' => $case === 'archived' ? now() : null])->save();
    $room = match ($case) {
        'another property' => Room::factory()->create(),
        'another room type' => Room::factory()->for($setup['property'])->create(['room_type_id' => RoomType::factory()->for($setup['property'])->create()->id]),
        'archived' => $setup['rooms'][0],
        'a dorm room for a room unit' => $setup['dormRoom'],
    };

    $result = app(AssignRoom::class)($setup['user'], $setup['unit'], $room);

    expect($result->refusal)->toBe(AssignmentResult::REFUSAL_ROOM_NOT_FOUND)
        ->and(UnitNight::query()->whereNotNull('room_id')->count())->toBe(0);
})->with(['another property', 'another room type', 'archived', 'a dorm room for a room unit']);

test('a unit that is not open takes no room', function (UnitStatus $status) {
    $setup = assignSetup();
    $setup['unit']->forceFill(['status' => $status, 'checked_in_at' => $status === UnitStatus::CheckedOut ? now() : null, 'checked_out_at' => $status === UnitStatus::CheckedOut ? now() : null])->save();

    expect(app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0])->refusal)->toBe(AssignmentResult::REFUSAL_UNIT_NOT_OPEN);
})->with([UnitStatus::Cancelled, UnitStatus::CheckedOut, UnitStatus::NoShow]);

test('a bed is assigned to every night of the unit and a taken bed is refused', function () {
    $setup = assignSetup();
    $other = assignUnit($setup['property'], $setup['dorm']);

    expect(app(AssignBed::class)($setup['user'], $setup['bedUnit'], $setup['beds'][0])->isRefused())->toBeFalse()
        ->and($setup['bedUnit']->nights()->pluck('bed_id')->unique()->all())->toBe([$setup['beds'][0]->id])
        ->and($setup['bedUnit']->nights()->whereNotNull('room_id')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.bed_assigned')->count())->toBe(1);

    $taken = app(AssignBed::class)($setup['user'], $other, $setup['beds'][0]);

    expect($taken->refusal)->toBe(AssignmentResult::REFUSAL_BED_NOT_FREE)
        ->and($taken->date->toDateString())->toBe('2026-10-20')
        ->and(app(AssignBed::class)($setup['user'], $other, $setup['beds'][1])->isRefused())->toBeFalse();
});

test('a bed that cannot be sold to the unit is refused as not found', function (string $case) {
    $setup = assignSetup();
    $setup['beds'][0]->forceFill(['archived_at' => $case === 'archived' ? now() : null])->save();
    $bed = $case === 'archived' ? $setup['beds'][0] : Bed::factory()->create();

    expect(app(AssignBed::class)($setup['user'], $setup['bedUnit'], $bed)->refusal)->toBe(AssignmentResult::REFUSAL_BED_NOT_FOUND);
})->with(['another property', 'archived']);

test('a bed cannot go to a room unit', function () {
    $setup = assignSetup();

    expect(app(AssignBed::class)($setup['user'], $setup['unit'], $setup['beds'][0])->refusal)->toBe(AssignmentResult::REFUSAL_BED_NOT_FOUND);
});
