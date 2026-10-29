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
    $this->changeType = fn (array $rooms, RoomType $type) => $this->actingAs($this->owner)->post(
        route('settings.rooms.change-type', $this->property->code),
        ['room_ids' => array_map(fn (Room $room): int => $room->id, $rooms), 'room_type_id' => $type->id],
    );
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

    ($this->changeType)([$room], $archived)->assertSessionHasErrors(['room_type_id' => 'Choose an active room type.']);
    ($this->changeType)([$room], $foreign)->assertSessionHasErrors('room_type_id');

    expect($room->refresh()->room_type_id)->toBe($this->standard->id);
});

test('the room ids are required, distinct and at most 200', function () {
    $room = ($this->room)('101');
    $url = route('settings.rooms.change-type', $this->property->code);

    $this->actingAs($this->owner)->post($url, ['room_ids' => [], 'room_type_id' => $this->deluxe->id])->assertSessionHasErrors('room_ids');
    $this->post($url, ['room_ids' => [$room->id, $room->id], 'room_type_id' => $this->deluxe->id])->assertSessionHasErrors('room_ids.0');
    $this->post($url, ['room_ids' => range(1, 201), 'room_type_id' => $this->deluxe->id])->assertSessionHasErrors('room_ids');
    $this->post(route('settings.rooms.archive-many', $this->property->code), ['room_ids' => range(1, 201)])->assertSessionHasErrors('room_ids');
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
    $this->post(route('settings.rooms.change-type', $code), ['room_ids' => [$room->id], 'room_type_id' => $this->deluxe->id])->assertForbidden();
    $this->post(route('settings.rooms.archive-many', $code), ['room_ids' => [$room->id]])->assertForbidden();

    $this->actingAs($this->owner)
        ->get(route('settings.rooms.index', $code))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true));

    expect($room->refresh()->room_type_id)->toBe($this->standard->id)->and($room->archived_at)->toBeNull();
});

test('another property\'s rooms are not reachable', function () {
    $own = ($this->room)('101');
    $foreign = Room::factory()->for(Property::factory())->create();
    $code = $this->property->code;

    ($this->changeType)([$own, $foreign], $this->deluxe)->assertNotFound();
    $this->post(route('settings.rooms.archive-many', $code), ['room_ids' => [$own->id, $foreign->id]])->assertNotFound();
    $this->post(route('settings.rooms.change-type', $foreign->property->code), ['room_ids' => [$foreign->id], 'room_type_id' => $foreign->room_type_id])->assertNotFound();

    expect($own->refresh()->room_type_id)->toBe($this->standard->id)
        ->and($own->archived_at)->toBeNull()
        ->and($foreign->refresh()->archived_at)->toBeNull();
});
