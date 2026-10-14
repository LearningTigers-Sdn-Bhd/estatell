<?php

use App\Enums\HousekeepingStatus;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a new room starts Clean', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();

    $this->actingAs($owner)->post(route('settings.rooms.store', $property->code), ['number' => '101', 'room_type_id' => $type->id]);

    $status = Room::query()->sole()->status;

    expect($status->status)->toBe(HousekeepingStatus::Clean)
        ->and($status->property_id)->toBe($property->id);
});

test('generated rooms each get one status row', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();

    $this->actingAs($owner)->post(route('settings.rooms.generate', $property->code), [
        'room_type_id' => $type->id,
        'mode' => 'range',
        'from' => '101',
        'to' => '103',
    ])->assertSessionHasNoErrors();

    expect(RoomStatus::query()->where('property_id', $property->id)->where('status', 'clean')->count())->toBe(3);
});

test('a room has at most one status row and only known statuses', function () {
    $room = Room::factory()->create();

    expect(fn () => RoomStatus::factory()->for($room)->create(['property_id' => $room->property_id]))->toThrow(QueryException::class)
        ->and(fn () => $room->status->forceFill(['status' => 'out_of_service'])->save())->toThrow(QueryException::class);
});

test('housekeeping moves Dirty to Clean to Inspected, and any room can become Dirty', function () {
    expect(HousekeepingStatus::Dirty->canTransitionTo(HousekeepingStatus::Clean))->toBeTrue()
        ->and(HousekeepingStatus::Dirty->canTransitionTo(HousekeepingStatus::Inspected))->toBeFalse()
        ->and(HousekeepingStatus::Clean->canTransitionTo(HousekeepingStatus::Inspected))->toBeTrue()
        ->and(HousekeepingStatus::Inspected->canTransitionTo(HousekeepingStatus::Dirty))->toBeTrue()
        ->and(HousekeepingStatus::Inspected->canTransitionTo(HousekeepingStatus::Clean))->toBeFalse();
});
