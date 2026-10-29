<?php

use App\Enums\PermissionEnum;
use App\Events\InventoryChanged;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = $this->owner->property;
    BusinessDate::factory()->create(['property_id' => $this->property->id, 'date' => '2026-10-14']);
    $this->standard = RoomType::factory()->for($this->property)->create(['name' => 'Standard']);
    $this->deluxe = RoomType::factory()->for($this->property)->create(['name' => 'Deluxe']);
    $this->room = fn (string $number, ?RoomType $type = null, int $position = 0): Room => Room::factory()->for($this->property)
        ->create(['number' => $number, 'room_type_id' => ($type ?? $this->standard)->id, 'position' => $position]);
    $this->ids = fn (array $rooms): array => array_map(fn (Room $room): int => $room->id, $rooms);
    $this->changeTypes = fn (array $changes) => $this->actingAs($this->owner)->post(
        route('settings.rooms.change-type', $this->property->code),
        ['changes' => array_map(fn (array $change): array => ['room_type_id' => $change[1]->id, 'room_ids' => ($this->ids)($change[0])], $changes)],
    );
    $this->changeType = fn (array $rooms, RoomType $type) => ($this->changeTypes)([[$rooms, $type]]);
});

test('the ticked rooms change type', function () {
    Event::fake([InventoryChanged::class]);
    $first = ($this->room)('101', position: 1);
    $second = ($this->room)('102', position: 2);

    ($this->changeType)([$first, $second], $this->deluxe)
        ->assertRedirect(route('settings.rooms.index', $this->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 rooms changed.']);

    expect($first->refresh()->room_type_id)->toBe($this->deluxe->id)
        ->and($second->refresh()->room_type_id)->toBe($this->deluxe->id)
        ->and(Activity::query()->where('event', 'room.updated')->count())->toBe(2);
    Event::assertDispatched(InventoryChanged::class);
});

test('rooms that pass change and the refused ones are listed in room order', function () {
    $passes = ($this->room)('101', position: 1);
    $dorm = RoomType::factory()->for($this->property)->bed()->create();
    $withBeds = ($this->room)('301', $dorm, 2);
    Bed::factory()->for($withBeds)->create();
    $withStay = ($this->room)('305', position: 3);
    UnitNight::factory()->create(['room_id' => $withStay->id, 'date' => '2026-10-20']);
    $sameType = ($this->room)('401', $this->deluxe, 4);

    ($this->changeType)([$withStay, $passes, $sameType, $withBeds], $this->deluxe)
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '1 room changed. 301 has beds. 305 has stays assigned from 20 Oct.']);

    expect($passes->refresh()->room_type_id)->toBe($this->deluxe->id)
        ->and($withBeds->refresh()->room_type_id)->toBe($dorm->id)
        ->and($withStay->refresh()->room_type_id)->toBe($this->standard->id)
        ->and(Activity::query()->where('event', 'room.updated')->pluck('subject_id')->all())->toBe([$passes->id]);
});

test('a room with beds moves into another type sold by bed', function () {
    $dorm = RoomType::factory()->for($this->property)->bed()->create();
    $otherDorm = RoomType::factory()->for($this->property)->bed()->create();
    $room = ($this->room)('301', $dorm);
    Bed::factory()->for($room)->create();

    ($this->changeType)([$room], $otherDorm)->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 room changed.']);

    expect($room->refresh()->room_type_id)->toBe($otherDorm->id);
});

test('a stay before the business date does not block the change', function () {
    $room = ($this->room)('305');
    UnitNight::factory()->create(['room_id' => $room->id, 'date' => '2026-10-13']);

    ($this->changeType)([$room], $this->deluxe)->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 room changed.']);
});

test('nothing changed says so, with the refusals', function () {
    $archived = ($this->room)('101');
    $archived->forceFill(['archived_at' => now()])->save();
    $sameType = ($this->room)('102', $this->deluxe);

    ($this->changeType)([$archived], $this->deluxe)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No rooms changed. 101 is already archived.']);

    ($this->changeType)([$sameType], $this->deluxe)
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No rooms changed.']);

    expect(Activity::query()->where('event', 'room.updated')->exists())->toBeFalse();
});

test('the toast lists 3 refused rooms, then how many more', function () {
    $rooms = collect(['101', '102', '103', '104', '105'])->map(function (string $number, int $position): Room {
        $room = ($this->room)($number, position: $position);
        UnitNight::factory()->create(['room_id' => $room->id, 'date' => '2026-10-20']);

        return $room;
    })->all();

    ($this->changeType)($rooms, $this->deluxe)->assertInertiaFlash('toast', [
        'type' => 'error',
        'message' => 'No rooms changed. 101 has stays assigned from 20 Oct. 102 has stays assigned from 20 Oct. 103 has stays assigned from 20 Oct. and 2 more.',
    ]);
});

test('the new room type must be an active type of the property', function () {
    $room = ($this->room)('101');
    $archived = RoomType::factory()->for($this->property)->create(['archived_at' => now()]);
    $foreign = RoomType::factory()->create();

    ($this->changeType)([$room], $archived)->assertSessionHasErrors(['changes.0.room_type_id' => 'Choose an active room type.']);
    ($this->changeType)([$room], $foreign)->assertSessionHasErrors('changes.0.room_type_id');

    expect($room->refresh()->room_type_id)->toBe($this->standard->id);
});

test('the changes are required, a room is in one change only, and all changes take at most 200 rooms', function () {
    $room = ($this->room)('101');
    $url = route('settings.rooms.change-type', $this->property->code);
    $change = fn (RoomType $type, array $ids): array => ['room_type_id' => $type->id, 'room_ids' => $ids];

    $this->actingAs($this->owner)->post($url, ['changes' => []])->assertSessionHasErrors('changes');
    $this->post($url, ['changes' => [$change($this->deluxe, [])]])->assertSessionHasErrors('changes.0.room_ids');
    $this->post($url, ['changes' => [$change($this->deluxe, [$room->id]), $change($this->standard, [$room->id])]])
        ->assertSessionHasErrors(['changes' => 'A room can have only one new room type.']);
    $this->post($url, ['changes' => [$change($this->deluxe, [$room->id, $room->id])]])->assertSessionHasErrors('changes');
    $this->post($url, ['changes' => [$change($this->deluxe, range(1, 200)), $change($this->standard, [$room->id])]])
        ->assertSessionHasErrors(['changes' => 'Change at most 200 rooms at once.']);
    $this->post(route('settings.rooms.archive-many', $this->property->code), ['room_ids' => range(1, 201)])->assertSessionHasErrors('room_ids');

    expect($room->refresh()->room_type_id)->toBe($this->standard->id)
        ->and(Activity::query()->where('event', 'room.updated')->exists())->toBeFalse();
});

test('one save can paint several room types', function () {
    $first = ($this->room)('101', position: 1);
    $second = ($this->room)('102', position: 2);
    $third = ($this->room)('103', position: 3);
    $suite = RoomType::factory()->for($this->property)->create(['name' => 'Suite']);

    ($this->changeTypes)([[[$first, $second], $this->deluxe], [[$third], $suite]])
        ->assertRedirect(route('settings.rooms.index', $this->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '3 rooms changed.']);

    expect($first->refresh()->room_type_id)->toBe($this->deluxe->id)
        ->and($second->refresh()->room_type_id)->toBe($this->deluxe->id)
        ->and($third->refresh()->room_type_id)->toBe($suite->id)
        ->and(Activity::query()->where('event', 'room.updated')->count())->toBe(3);
});

test('the save goes back to the Map tab it came from', function () {
    $room = ($this->room)('101');
    $code = $this->property->code;

    $this->actingAs($this->owner)->from(route('settings.rooms.index', [$code, 'view' => 'map']))
        ->post(route('settings.rooms.change-type', $code), ['changes' => [['room_type_id' => $this->deluxe->id, 'room_ids' => [$room->id]]]])
        ->assertRedirect(route('settings.rooms.index', [$code, 'view' => 'map']));
});

test('a refusal in one change does not stop the others, and the toast joins them', function () {
    $passes = ($this->room)('101', position: 1);
    $withStay = ($this->room)('305', position: 2);
    UnitNight::factory()->create(['room_id' => $withStay->id, 'date' => '2026-10-20']);
    $other = ($this->room)('402', $this->deluxe, 3);
    $suite = RoomType::factory()->for($this->property)->create(['name' => 'Suite']);

    ($this->changeTypes)([[[$withStay], $this->deluxe], [[$passes, $other], $suite]])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '2 rooms changed. 305 has stays assigned from 20 Oct.']);

    expect($withStay->refresh()->room_type_id)->toBe($this->standard->id)
        ->and($passes->refresh()->room_type_id)->toBe($suite->id)
        ->and($other->refresh()->room_type_id)->toBe($suite->id);
});

test('an archived room type in any change refuses the whole request', function () {
    $first = ($this->room)('101', position: 1);
    $second = ($this->room)('102', position: 2);
    $archived = RoomType::factory()->for($this->property)->create(['archived_at' => now()]);

    ($this->changeTypes)([[[$first], $this->deluxe], [[$second], $archived]])
        ->assertSessionHasErrors(['changes.1.room_type_id' => 'Choose an active room type.']);

    expect($first->refresh()->room_type_id)->toBe($this->standard->id)
        ->and($second->refresh()->room_type_id)->toBe($this->standard->id);
});

test('a foreign room in a later change returns 404 and changes nothing', function () {
    $own = ($this->room)('101');
    $foreign = Room::factory()->for(Property::factory())->create();

    ($this->changeTypes)([[[$own], $this->deluxe], [[$foreign], $this->standard]])->assertNotFound();

    expect($own->refresh()->room_type_id)->toBe($this->standard->id);
});

test('the ticked rooms are archived and already archived ones are listed', function () {
    $first = ($this->room)('101', position: 1);
    $second = ($this->room)('102', position: 2);
    $second->forceFill(['archived_at' => now()])->save();

    $this->actingAs($this->owner)
        ->post(route('settings.rooms.archive-many', $this->property->code), ['room_ids' => [$first->id, $second->id]])
        ->assertRedirect(route('settings.rooms.index', $this->property->code))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '1 room archived. 102 is already archived.']);

    expect($first->refresh()->archived_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'room.archived')->count())->toBe(1);
});

test('the bulk actions need rooms.manage, and the page says whether tick boxes show', function () {
    $room = ($this->room)('101');
    $viewer = User::factory()->for($this->property)->withPermissions([PermissionEnum::RoomsView])->create();
    $code = $this->property->code;

    $this->actingAs($viewer)
        ->get(route('settings.rooms.index', $code))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post(route('settings.rooms.change-type', $code), ['changes' => [['room_type_id' => $this->deluxe->id, 'room_ids' => [$room->id]]]])->assertForbidden();
    $this->post(route('settings.rooms.archive-many', $code), ['room_ids' => [$room->id]])->assertForbidden();

    $this->actingAs($this->owner)
        ->get(route('settings.rooms.index', $code))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true));

    expect($room->refresh()->room_type_id)->toBe($this->standard->id)->and($room->archived_at)->toBeNull();
});

test('the page says which tab is open', function () {
    $code = $this->property->code;

    $this->actingAs($this->owner)->get(route('settings.rooms.index', $code))
        ->assertInertia(fn (Assert $page) => $page->where('view', 'list'));
    $this->get(route('settings.rooms.index', [$code, 'view' => 'map']))
        ->assertInertia(fn (Assert $page) => $page->where('view', 'map')->has('rooms')->has('roomTypes'));
    $this->get(route('settings.rooms.index', [$code, 'view' => 'other']))
        ->assertInertia(fn (Assert $page) => $page->where('view', 'list'));
});

test('another property\'s rooms are not reachable', function () {
    $own = ($this->room)('101');
    $foreign = Room::factory()->for(Property::factory())->create();
    $code = $this->property->code;

    ($this->changeType)([$own, $foreign], $this->deluxe)->assertNotFound();
    $this->post(route('settings.rooms.archive-many', $code), ['room_ids' => [$own->id, $foreign->id]])->assertNotFound();
    $this->post(route('settings.rooms.change-type', $foreign->property->code), ['changes' => [['room_type_id' => $foreign->room_type_id, 'room_ids' => [$foreign->id]]]])->assertNotFound();

    expect($own->refresh()->room_type_id)->toBe($this->standard->id)
        ->and($own->archived_at)->toBeNull()
        ->and($foreign->refresh()->archived_at)->toBeNull();
});
