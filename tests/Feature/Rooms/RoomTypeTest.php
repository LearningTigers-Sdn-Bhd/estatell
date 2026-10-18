<?php

use App\Actions\Rooms\ReorderRoomTypes;
use App\Enums\DefaultRoleEnum;
use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function roomTypePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Deluxe King',
        'description' => 'Sea view',
        'sell_unit' => 'room',
        'max_adults' => 2,
        'max_children' => 1,
        'base_occupancy' => 2,
        'smoking_allowed' => false,
        'pets_allowed' => true,
    ], $overrides);
}

test('the list shows active room types in order, then archived ones', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    RoomType::factory()->for($property)->archived()->create(['name' => 'Old', 'position' => 1]);
    RoomType::factory()->for($property)->create(['name' => 'Second', 'position' => 3]);
    RoomType::factory()->for($property)->bed()->create(['name' => 'Dorm', 'position' => 2]);
    RoomType::factory()->create(['name' => 'Other property']);

    $this->actingAs($owner)
        ->get(route('settings.room-types.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/room-types/index')
            ->has('roomTypes', 3)
            ->where('roomTypes', fn ($roomTypes) => collect($roomTypes)->pluck('name')->all() === ['Dorm', 'Second', 'Old'])
            ->where('roomTypes.0.sellUnit', 'bed')
            ->where('roomTypes.2.archived', true)
            ->where('sellUnits', SellUnit::options()));
});

test('owners create a room type at the end of the list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    RoomType::factory()->for($property)->create(['position' => 4]);

    $this->actingAs($owner)
        ->post(route('settings.room-types.store', $property->code), roomTypePayload(['name' => ' Deluxe King ']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Deluxe King created.']);

    $roomType = RoomType::query()->where('name', 'Deluxe King')->sole();

    $this->get(route('settings.room-types.edit', [$property->code, $roomType]))->assertOk();

    expect($roomType->property_id)->toBe($property->id)
        ->and($roomType->position)->toBe(5)
        ->and($roomType->sell_unit)->toBe(SellUnit::Room)
        ->and($roomType->pets_allowed)->toBeTrue()
        ->and(Activity::query()->where('event', 'room_type.created')->count())->toBe(1);
});

test('room type fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    RoomType::factory()->for($owner->property)->create(['name' => 'Deluxe King']);

    $this->actingAs($owner)
        ->post(route('settings.room-types.store', $owner->property->code), roomTypePayload($overrides))
        ->assertSessionHasErrors($field);

    expect(RoomType::query()->count())->toBe(1);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name is unique among active types, ignoring case' => [['name' => 'deluxe KING'], 'name'],
    'sell unit is a known value' => [['sell_unit' => 'suite'], 'sell_unit'],
    'at least one adult' => [['max_adults' => 0], 'max_adults'],
    'guests included fit the maximum' => [['base_occupancy' => 4], 'base_occupancy'],
]);

test('an archived room type frees its name', function () {
    $owner = User::factory()->owner()->create();
    RoomType::factory()->for($owner->property)->archived()->create(['name' => 'Deluxe King']);

    $this->actingAs($owner)
        ->post(route('settings.room-types.store', $owner->property->code), roomTypePayload())
        ->assertSessionHasNoErrors();

    expect(RoomType::query()->count())->toBe(2);
});

test('the database rejects impossible occupancy', function () {
    RoomType::factory()->create(['max_adults' => 2, 'max_children' => 0, 'base_occupancy' => 3]);
})->throws(QueryException::class);

test('owners update a room type and only changed edits are logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Standard']);
    $url = route('settings.room-types.update', [$property->code, $roomType]);

    $this->actingAs($owner)
        ->put($url, roomTypePayload(['name' => 'Standard', 'description' => 'Sea view', 'sell_unit' => 'room', 'pets_allowed' => false, 'max_adults' => 3]))
        ->assertRedirect(route('settings.room-types.edit', [$property->code, $roomType]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Standard saved.']);

    expect($roomType->refresh()->max_adults)->toBe(3)
        ->and(Activity::query()->where('event', 'room_type.updated')->sole()->properties['changed'])->toContain('max_adults');

    $this->put($url, roomTypePayload(['name' => 'Standard', 'max_adults' => 3, 'pets_allowed' => false]));

    expect(Activity::query()->where('event', 'room_type.updated')->count())->toBe(1);
});

test('an archived room type cannot be edited', function () {
    $owner = User::factory()->owner()->create();
    $roomType = RoomType::factory()->for($owner->property)->archived()->create(['name' => 'Old']);

    $this->actingAs($owner)
        ->put(route('settings.room-types.update', [$owner->property->code, $roomType]), roomTypePayload(['name' => 'New']))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Old is archived. Restore it before editing.']);

    expect($roomType->refresh()->name)->toBe('Old');
});

test('archiving and restoring move a room type out of and back into the active list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    RoomType::factory()->for($property)->create(['position' => 1]);
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Suite', 'position' => 2]);

    $this->actingAs($owner)
        ->post(route('settings.room-types.archive', [$property->code, $roomType]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Suite archived.']);

    expect($roomType->refresh()->archived_at)->not->toBeNull();

    $this->post(route('settings.room-types.archive', [$property->code, $roomType]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Suite is already archived.']);

    expect(Activity::query()->where('event', 'room_type.archived')->count())->toBe(1);

    $this->post(route('settings.room-types.restore', [$property->code, $roomType]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Suite restored.']);

    expect($roomType->refresh()->archived_at)->toBeNull()
        ->and($roomType->position)->toBe(3);
});

test('archive and restore return to the page they were sent from', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create();
    $page = route('settings.room-types.edit', [$property->code, $roomType]);

    $this->actingAs($owner)
        ->from($page)
        ->post(route('settings.room-types.archive', [$property->code, $roomType]))
        ->assertRedirect($page);

    $this->from($page)
        ->post(route('settings.room-types.restore', [$property->code, $roomType]))
        ->assertRedirect($page);

    $list = route('settings.room-types.index', $property->code);

    $this->from($list)
        ->post(route('settings.room-types.archive', [$property->code, $roomType]))
        ->assertRedirect($list);
});

test('a room type cannot be restored while an active one has its name', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $archived = RoomType::factory()->for($property)->archived()->create(['name' => 'Suite']);
    RoomType::factory()->for($property)->create(['name' => 'Suite']);

    $this->actingAs($owner)
        ->post(route('settings.room-types.restore', [$property->code, $archived]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Suite cannot be restored. Another room type uses the same name.']);

    expect($archived->refresh()->archived_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'room_type.restored')->count())->toBe(0);
});

test('reordering saves the new positions and rejects a stale list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$a, $b, $c] = RoomType::factory()->count(3)->for($property)->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $url = route('settings.room-types.reorder', $property->code);

    $this->actingAs($owner)->put($url, ['order' => [$c->id, $a->id, $b->id]]);

    expect(RoomType::query()->ordered()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);

    $this->put($url, ['order' => [$a->id, $b->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The room types changed. Reload the page and try again.']);

    expect(RoomType::query()->ordered()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and(Activity::query()->where('event', 'room_type.reordered')->count())->toBe(1);
});

test('reordering refuses room types from another property', function () {
    $property = Property::factory()->create();
    $mine = RoomType::factory()->for($property)->create();
    $theirs = RoomType::factory()->create();
    $actor = User::factory()->owner()->create();

    expect(app(ReorderRoomTypes::class)($property, $actor, [$theirs->id]))->toBeFalse()
        ->and(app(ReorderRoomTypes::class)($property, $actor, [$mine->id]))->toBeTrue();
});

test('viewing needs rooms.view and changing needs rooms.manage', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $roomType = RoomType::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.room-types.index', $code))->assertForbidden();
    $this->post(route('settings.room-types.store', $code), roomTypePayload())->assertForbidden();
    $this->put(route('settings.room-types.update', [$code, $roomType]), roomTypePayload())->assertForbidden();
    $this->put(route('settings.room-types.reorder', $code), ['order' => [$roomType->id]])->assertForbidden();
    $this->post(route('settings.room-types.archive', [$code, $roomType]))->assertForbidden();
    $this->post(route('settings.room-types.restore', [$code, $roomType]))->assertForbidden();
});

test('another property\'s room types and codes are not reachable', function () {
    $owner = User::factory()->owner()->create();
    $other = Property::factory()->create();
    $theirs = RoomType::factory()->for($other)->create(['name' => 'Theirs']);

    $this->actingAs($owner)
        ->put(route('settings.room-types.update', [$owner->property->code, $theirs]), roomTypePayload())
        ->assertNotFound();
    $this->post(route('settings.room-types.archive', [$owner->property->code, $theirs]))->assertNotFound();
    $this->get(route('settings.room-types.index', $other->code))->assertNotFound();

    expect($theirs->refresh()->archived_at)->toBeNull()->and($theirs->name)->toBe('Theirs');
});

test('the settings sidebar lists room types only with rooms.view', function () {
    $owner = User::factory()->owner()->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($owner)
        ->get(route('settings.room-types.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.3.groups.1.label', 'Rooms')
            ->where('navigation.contexts.3.groups.1.items.0.title', 'Room types')
            ->where('navigation.contexts.3.groups.1.items.0.active', true));

    $this->actingAs($frontDesk)
        ->get(route('dashboard', $frontDesk->property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('navigation.contexts.2.groups.1.items.0'));
});

test('the store redirect opens the new room type page', function () {
    $owner = User::factory()->owner()->create();

    $response = $this->actingAs($owner)->post(route('settings.room-types.store', $owner->property->code), roomTypePayload());

    $response->assertRedirect(route('settings.room-types.edit', [$owner->property->code, RoomType::query()->sole()]));
});

test('the Details tab shows the room type and the room count', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    Room::factory()->for($property)->count(2)->create(['room_type_id' => $roomType->id]);
    Room::factory()->for($property)->archived()->create(['room_type_id' => $roomType->id]);

    $this->actingAs($owner)
        ->get(route('settings.room-types.edit', [$property->code, $roomType]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/room-types/edit')
            ->where('roomType.name', 'Deluxe')
            ->where('roomCount', 2)
            ->where('canManage', true));
});

test('staff with rooms.view open both tabs read-only and cannot save', function () {
    $viewer = User::factory()->withPermissions(['rooms.view'])->create();
    $property = $viewer->property;
    $roomType = RoomType::factory()->for($property)->create();

    $this->actingAs($viewer)
        ->get(route('settings.room-types.edit', [$property->code, $roomType]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/room-types/edit')->where('canManage', false));
    $this->get(route('settings.room-types.rooms.edit', [$property->code, $roomType]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/room-types/rooms')->where('canManage', false));
    $this->put(route('settings.room-types.rooms.update', [$property->code, $roomType]), ['rooms' => []])->assertForbidden();
});

test('another property\'s room type page is not reachable', function () {
    $owner = User::factory()->owner()->create();
    $theirs = RoomType::factory()->for(Property::factory()->create())->create();

    $this->actingAs($owner)->get(route('settings.room-types.edit', [$owner->property->code, $theirs]))->assertNotFound();
    $this->get(route('settings.room-types.rooms.edit', [$owner->property->code, $theirs]))->assertNotFound();
});

test('the Rooms tab lists the type\'s active rooms and the active rooms of other types', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create();
    $other = RoomType::factory()->for($property)->create(['name' => 'Standard Twin']);
    RoomGroup::factory()->for($property)->create(['name' => 'Floor 1']);
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101', 'position' => 1]);
    Room::factory()->for($property)->archived()->create(['room_type_id' => $roomType->id, 'number' => '102']);
    Room::factory()->for($property)->create(['room_type_id' => $other->id, 'number' => '305']);
    Room::factory()->for($property)->archived()->create(['room_type_id' => $other->id, 'number' => '306']);
    Room::factory()->create(['number' => '999']);

    $this->actingAs($owner)
        ->get(route('settings.room-types.rooms.edit', [$property->code, $roomType]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/room-types/rooms')
            ->has('rooms', 1)
            ->where('rooms.0.number', '101')
            ->has('otherRooms', 1)
            ->where('otherRooms.0.number', '305')
            ->where('otherRooms.0.roomTypeName', 'Standard Twin')
            ->has('roomGroups', 1)
            ->where('roomCount', 1));
});

/**
 * @param  list<array{0: Room, 1: int|null}>  $rows  Room and group id pairs.
 * @return array{rooms: list<array{room_id: int, room_group_id: int|null}>}
 */
function roomRows(array $rows): array
{
    return ['rooms' => array_map(fn (array $row): array => ['room_id' => $row[0]->id, 'room_group_id' => $row[1]], $rows)];
}

test('saving the Rooms tab changes groups and moves rooms in, logging only changed rooms', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create();
    $other = RoomType::factory()->for($property)->create();
    $group = RoomGroup::factory()->for($property)->create();
    $kept = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $regrouped = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $moving = Room::factory()->for($property)->create(['room_type_id' => $other->id, 'number' => '305']);
    $url = route('settings.room-types.rooms.update', [$property->code, $roomType]);

    $this->actingAs($owner)
        ->put($url, roomRows([[$kept, null], [$regrouped, $group->id], [$moving, $group->id]]))
        ->assertRedirect(route('settings.room-types.rooms.edit', [$property->code, $roomType]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rooms saved.']);

    expect($regrouped->refresh()->room_group_id)->toBe($group->id)
        ->and($moving->refresh()->room_type_id)->toBe($roomType->id)
        ->and($moving->room_group_id)->toBe($group->id)
        ->and($kept->refresh()->room_group_id)->toBeNull()
        ->and(Activity::query()->where('event', 'room.updated')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'room.updated')->where('subject_id', $moving->id)->sole()->properties['changed'])->toContain('room_type_id', 'room_group_id');

    $this->put($url, roomRows([[$kept, null], [$regrouped, $group->id], [$moving, $group->id]]));

    expect(Activity::query()->where('event', 'room.updated')->count())->toBe(2);
});

test('the Rooms tab refuses to leave a room out of the list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create();
    $group = RoomGroup::factory()->for($property)->create();
    $stays = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $left = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);

    $this->actingAs($owner)
        ->put(route('settings.room-types.rooms.update', [$property->code, $roomType]), roomRows([[$stays, $group->id]]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Rooms can\'t be removed here. Add them on another room type.']);

    expect($stays->refresh()->room_group_id)->toBeNull()->and($left->refresh()->room_type_id)->toBe($roomType->id)
        ->and(Activity::query()->where('event', 'room.updated')->count())->toBe(0);
});

test('a room with beds cannot move into a type that sells rooms', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create();
    $dorm = Room::factory()->for($property)->create(['room_type_id' => RoomType::factory()->for($property)->bed()->create()->id, 'number' => '305']);
    Bed::factory()->for($dorm)->create();

    $this->actingAs($owner)
        ->put(route('settings.room-types.rooms.update', [$property->code, $roomType]), roomRows([[$dorm, null]]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => '305 has beds. Choose a room type that sells beds.']);

    expect($dorm->refresh()->room_type_id)->not->toBe($roomType->id);
});

test('a room with stays from the business date cannot move, and one move refuses the whole save', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    $roomType = RoomType::factory()->for($property)->create();
    $other = RoomType::factory()->for($property)->create();
    $free = Room::factory()->for($property)->create(['room_type_id' => $other->id, 'number' => '204']);
    $busy = Room::factory()->for($property)->create(['room_type_id' => $other->id, 'number' => '305']);
    UnitNight::factory()->create(['room_id' => $busy->id, 'date' => '2026-10-20']);
    UnitNight::factory()->create(['room_id' => $busy->id, 'date' => '2026-10-22']);
    $url = route('settings.room-types.rooms.update', [$property->code, $roomType]);

    $this->actingAs($owner)
        ->put($url, roomRows([[$free, null], [$busy, null]]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => '305 has stays assigned from 20 Oct. Move them first.']);

    expect($free->refresh()->room_type_id)->toBe($other->id)->and($busy->refresh()->room_type_id)->toBe($other->id);

    UnitNight::query()->where('room_id', $busy->id)->delete();
    UnitNight::factory()->create(['room_id' => $busy->id, 'date' => '2026-10-13']);

    $this->put($url, roomRows([[$free, null], [$busy, null]]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rooms saved.']);

    expect($busy->refresh()->room_type_id)->toBe($roomType->id);
});

test('stays on a bed of the room also block the move', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    $bedType = RoomType::factory()->for($property)->bed()->create();
    $target = RoomType::factory()->for($property)->bed()->create();
    $dorm = Room::factory()->for($property)->create(['room_type_id' => $bedType->id, 'number' => 'D1']);
    $bed = Bed::factory()->for($dorm)->create();
    UnitNight::factory()->create(['bed_id' => $bed->id, 'date' => '2026-10-14']);

    $this->actingAs($owner)
        ->put(route('settings.room-types.rooms.update', [$property->code, $target]), roomRows([[$dorm, null]]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'D1 has stays assigned from 14 Oct. Move them first.']);
});

test('an unreleased day use from the business date blocks the move', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    $roomType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => RoomType::factory()->for($property)->create()->id, 'number' => '305']);
    UnitDayUse::factory()->create(['room_id' => $room->id, 'date' => '2026-10-13']);
    UnitDayUse::factory()->create(['room_id' => $room->id, 'date' => '2026-10-15', 'released_at' => now()]);
    $blocking = UnitDayUse::factory()->create(['room_id' => $room->id, 'date' => '2026-10-16']);
    $url = route('settings.room-types.rooms.update', [$property->code, $roomType]);

    $this->actingAs($owner)
        ->put($url, roomRows([[$room, null]]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => '305 has stays assigned from 16 Oct. Move them first.']);

    $blocking->update(['released_at' => now()]);

    $this->put($url, roomRows([[$room, null]]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rooms saved.']);
});

test('an archived room type refuses the Rooms tab save', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->archived()->create(['name' => 'Old']);
    $room = Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('settings.room-types.rooms.update', [$property->code, $roomType]), roomRows([[$room, null]]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Old is archived. Restore it before editing.']);

    expect($room->refresh()->room_type_id)->not->toBe($roomType->id);
});

test('the Rooms tab save refuses rooms and groups of another property and archived rooms', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $roomType = RoomType::factory()->for($property)->create();
    $mine = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $theirRoom = Room::factory()->create();
    $theirGroup = RoomGroup::factory()->create();
    $archived = Room::factory()->for($property)->archived()->create();
    $url = route('settings.room-types.rooms.update', [$property->code, $roomType]);

    $this->actingAs($owner)->put($url, roomRows([[$mine, null], [$theirRoom, null]]))->assertSessionHasErrors('rooms.1.room_id');
    $this->put($url, roomRows([[$mine, $theirGroup->id]]))->assertSessionHasErrors('rooms.0.room_group_id');
    $this->put($url, roomRows([[$mine, null], [$archived, null]]))->assertSessionHasErrors('rooms.1.room_id');
    $this->put($url, roomRows([[$mine, null], [$mine, null]]))->assertSessionHasErrors('rooms.0.room_id');

    expect($theirRoom->refresh()->room_type_id)->not->toBe($roomType->id);
});

test('changing rooms needs rooms.manage', function () {
    $viewer = User::factory()->withPermissions(['rooms.view'])->create();
    $roomType = RoomType::factory()->for($viewer->property)->create();

    $this->actingAs($viewer)->put(route('settings.room-types.rooms.update', [$viewer->property->code, $roomType]), ['rooms' => []])->assertForbidden();
});
