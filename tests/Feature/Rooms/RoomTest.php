<?php

use App\Enums\DefaultRoleEnum;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('the page lists the property\'s rooms, groups and room types only', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    $group = RoomGroup::factory()->for($property)->create(['name' => 'Floor 1']);
    Room::factory()->for($property)->create(['room_type_id' => $type->id, 'room_group_id' => $group->id, 'number' => '101', 'position' => 2]);
    Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '100', 'position' => 1]);
    Room::factory()->create(['number' => '999']);

    $this->actingAs($owner)
        ->get(route('settings.rooms.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rooms/index')
            ->has('rooms', 2)
            ->where('rooms.0.number', '100')
            ->where('rooms.1.roomGroupId', $group->id)
            ->has('roomGroups', 1)
            ->has('roomTypes', 1)
            ->where('generateLimit', 200));
});

test('owners create a room', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    $group = RoomGroup::factory()->for($property)->create();
    Room::factory()->for($property)->create(['position' => 4]);

    $this->actingAs($owner)
        ->post(route('settings.rooms.store', $property->code), ['number' => ' 201A ', 'room_type_id' => $type->id, 'room_group_id' => $group->id])
        ->assertRedirect(route('settings.rooms.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room 201A created.']);

    $room = Room::query()->where('number', '201A')->sole();

    expect($room->property_id)->toBe($property->id)
        ->and($room->room_group_id)->toBe($group->id)
        ->and($room->position)->toBe(5)
        ->and(Activity::query()->where('event', 'room.created')->count())->toBe(1);
});

test('room fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['number' => '101']);
    $archivedType = RoomType::factory()->for($property)->archived()->create();
    $foreignType = RoomType::factory()->create();
    $foreignGroup = RoomGroup::factory()->create();

    $payload = array_merge(['number' => '102', 'room_type_id' => $type->id], array_map(
        function ($value) use ($archivedType, $foreignType, $foreignGroup) {
            return is_callable($value) ? $value(compact('archivedType', 'foreignType', 'foreignGroup')) : $value;
        },
        $overrides,
    ));

    $this->actingAs($owner)
        ->post(route('settings.rooms.store', $property->code), $payload)
        ->assertSessionHasErrors($field);

    expect(Room::query()->where('property_id', $property->id)->count())->toBe(1);
})->with([
    'number is required' => [['number' => ''], 'number'],
    'number is unique among active rooms, ignoring case' => [['number' => '101'], 'number'],
    'number is at most 30 characters' => [['number' => str_repeat('a', 31)], 'number'],
    'room type is active' => [['room_type_id' => fn ($models) => $models['archivedType']->id], 'room_type_id'],
    'room type belongs to the property' => [['room_type_id' => fn ($models) => $models['foreignType']->id], 'room_type_id'],
    'group belongs to the property' => [['room_group_id' => fn ($models) => $models['foreignGroup']->id], 'room_group_id'],
]);

test('an archived room frees its number', function () {
    $owner = User::factory()->owner()->create();
    $type = RoomType::factory()->for($owner->property)->create();
    Room::factory()->for($owner->property)->archived()->create(['number' => '101']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.store', $owner->property->code), ['number' => '101', 'room_type_id' => $type->id])
        ->assertSessionHasNoErrors();

    expect(Room::query()->count())->toBe(2);
});

test('the database rejects duplicate active numbers and blank numbers', function () {
    $room = Room::factory()->create(['number' => 'A1']);

    expect(fn () => Room::factory()->create(['property_id' => $room->property_id, 'room_type_id' => $room->room_type_id, 'number' => 'a1']))
        ->toThrow(QueryException::class)
        ->and(fn () => Room::factory()->create(['number' => ' ']))
        ->toThrow(QueryException::class);
});

test('owners update a room and only changed edits are logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    $group = RoomGroup::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '101']);
    $url = route('settings.rooms.update', [$property->code, $room]);

    $this->actingAs($owner)
        ->put($url, ['number' => '102', 'room_type_id' => $type->id, 'room_group_id' => $group->id])
        ->assertRedirect(route('settings.rooms.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room 102 saved.']);

    expect($room->refresh()->number)->toBe('102')
        ->and($room->room_group_id)->toBe($group->id)
        ->and(Activity::query()->where('event', 'room.updated')->sole()->properties['changed'])->toContain('number', 'room_group_id');

    $this->put($url, ['number' => '102', 'room_type_id' => $type->id, 'room_group_id' => $group->id]);

    expect(Activity::query()->where('event', 'room.updated')->count())->toBe(1);
});

test('a room may keep an archived room type but cannot move to one', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $archivedType = RoomType::factory()->for($property)->archived()->create();
    $activeType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $archivedType->id, 'number' => '101']);
    $url = route('settings.rooms.update', [$property->code, $room]);

    $this->actingAs($owner)->put($url, ['number' => '102', 'room_type_id' => $archivedType->id])->assertSessionHasNoErrors();
    expect($room->refresh()->number)->toBe('102');

    $this->put($url, ['number' => '102', 'room_type_id' => $activeType->id])->assertSessionHasNoErrors();
    expect($room->refresh()->room_type_id)->toBe($activeType->id);

    $this->put($url, ['number' => '102', 'room_type_id' => $archivedType->id])->assertSessionHasErrors('room_type_id');
});

test('an archived room cannot be edited', function () {
    $owner = User::factory()->owner()->create();
    $type = RoomType::factory()->for($owner->property)->create();
    $room = Room::factory()->for($owner->property)->archived()->create(['room_type_id' => $type->id, 'number' => '101']);

    $this->actingAs($owner)
        ->put(route('settings.rooms.update', [$owner->property->code, $room]), ['number' => '102', 'room_type_id' => $type->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room 101 is archived. Restore it before editing.']);

    expect($room->refresh()->number)->toBe('101');
});

test('archiving and restoring a room', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create(['number' => '101']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.archive', [$property->code, $room]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room 101 archived.']);

    expect($room->refresh()->archived_at)->not->toBeNull();

    $this->post(route('settings.rooms.archive', [$property->code, $room]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room 101 is already archived.']);

    $this->post(route('settings.rooms.restore', [$property->code, $room]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room 101 restored.']);

    expect($room->refresh()->archived_at)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['room.archived', 'room.restored'])->count())->toBe(2);
});

test('a room cannot be restored while an active room uses its number', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $old = Room::factory()->for($property)->archived()->create(['number' => '101']);
    Room::factory()->for($property)->create(['number' => '101']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.restore', [$property->code, $old]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room 101 cannot be restored. Another room uses the same number.']);

    expect($old->refresh()->archived_at)->not->toBeNull();
});

test('sold by is locked once a room type has rooms', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create(['name' => 'Standard']);
    $payload = ['name' => 'Standard', 'sell_unit' => 'bed', 'max_adults' => 1, 'max_children' => 0, 'base_occupancy' => 1];
    $url = route('settings.room-types.update', [$property->code, $type]);

    $this->actingAs($owner)->put($url, $payload)->assertSessionHasNoErrors();
    expect($type->refresh()->sell_unit->value)->toBe('bed');

    Room::factory()->for($property)->create(['room_type_id' => $type->id]);

    $this->put($url, [...$payload, 'sell_unit' => 'room'])->assertSessionHasErrors('sell_unit');
    $this->put($url, [...$payload, 'max_adults' => 2])->assertSessionHasNoErrors();
    expect($type->refresh()->sell_unit->value)->toBe('bed');
});

test('viewing needs rooms.view and changing needs rooms.manage', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $room = Room::factory()->for($frontDesk->property)->create();
    $group = RoomGroup::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.rooms.index', $code))->assertForbidden();
    $this->post(route('settings.rooms.store', $code), [])->assertForbidden();
    $this->post(route('settings.rooms.generate.preview', $code), [])->assertForbidden();
    $this->post(route('settings.rooms.generate', $code), [])->assertForbidden();
    $this->put(route('settings.rooms.update', [$code, $room]), [])->assertForbidden();
    $this->post(route('settings.rooms.archive', [$code, $room]))->assertForbidden();
    $this->post(route('settings.rooms.restore', [$code, $room]))->assertForbidden();
    $this->post(route('settings.room-groups.store', $code), [])->assertForbidden();
    $this->put(route('settings.room-groups.update', [$code, $group]), [])->assertForbidden();
    $this->put(route('settings.room-groups.reorder', $code), [])->assertForbidden();
    $this->delete(route('settings.room-groups.destroy', [$code, $group]))->assertForbidden();
});

test('another property\'s rooms and groups are not reachable', function () {
    $owner = User::factory()->owner()->create();
    $other = Property::factory()->create();
    $room = Room::factory()->for($other)->create();
    $group = RoomGroup::factory()->for($other)->create();
    $code = $owner->property->code;

    $this->actingAs($owner)->put(route('settings.rooms.update', [$code, $room]), ['number' => 'x', 'room_type_id' => $room->room_type_id])->assertNotFound();
    $this->post(route('settings.rooms.archive', [$code, $room]))->assertNotFound();
    $this->put(route('settings.room-groups.update', [$code, $group]), ['name' => 'x'])->assertNotFound();
    $this->delete(route('settings.room-groups.destroy', [$code, $group]))->assertNotFound();
    $this->get(route('settings.rooms.index', $other->code))->assertNotFound();

    expect($room->refresh()->archived_at)->toBeNull()->and(RoomGroup::query()->whereKey($group->id)->exists())->toBeTrue();
});

test('a room with stays from the business date cannot change type', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    $type = RoomType::factory()->for($property)->create();
    $newType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '305']);
    $night = UnitNight::factory()->create(['room_id' => $room->id, 'date' => '2026-10-20']);
    $url = route('settings.rooms.update', [$property->code, $room]);

    $this->actingAs($owner)
        ->put($url, ['number' => '305', 'room_type_id' => $newType->id])
        ->assertSessionHasErrors(['room_type_id' => '305 has stays assigned from 20 Oct. Move them first.']);

    $this->put($url, ['number' => '306', 'room_type_id' => $type->id])->assertSessionHasNoErrors();

    $night->delete();
    UnitNight::factory()->create(['room_id' => $room->id, 'date' => '2026-10-13']);

    $this->put($url, ['number' => '306', 'room_type_id' => $newType->id])->assertSessionHasNoErrors();
    expect($room->refresh()->room_type_id)->toBe($newType->id);
});
