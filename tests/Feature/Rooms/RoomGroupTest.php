<?php

use App\Actions\Rooms\ReorderRoomGroups;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

test('owners create a room group at the end of the list', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    RoomGroup::factory()->for($property)->create(['position' => 3]);

    $this->actingAs($owner)
        ->post(route('settings.room-groups.store', $property->code), ['name' => ' Left wing '])
        ->assertRedirect(route('settings.rooms.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Left wing created.']);

    $group = RoomGroup::query()->where('name', 'Left wing')->sole();

    expect($group->property_id)->toBe($property->id)
        ->and($group->position)->toBe(4)
        ->and(Activity::query()->where('event', 'room_group.created')->count())->toBe(1);
});

test('room group names are required and unique per property, ignoring case', function (string $name) {
    $owner = User::factory()->owner()->create();
    RoomGroup::factory()->for($owner->property)->create(['name' => 'Floor 1']);
    RoomGroup::factory()->create(['name' => 'Floor 2']);

    $this->actingAs($owner)
        ->post(route('settings.room-groups.store', $owner->property->code), ['name' => $name])
        ->assertSessionHasErrors('name');

    expect(RoomGroup::query()->where('property_id', $owner->property->id)->count())->toBe(1);
})->with(['empty' => [''], 'duplicate' => ['floor 1']]);

test('another property may use the same group name', function () {
    $owner = User::factory()->owner()->create();
    RoomGroup::factory()->create(['name' => 'Floor 1']);

    $this->actingAs($owner)
        ->post(route('settings.room-groups.store', $owner->property->code), ['name' => 'Floor 1'])
        ->assertSessionHasNoErrors();
});

test('the database rejects duplicate group names', function () {
    $group = RoomGroup::factory()->create(['name' => 'Wing']);

    RoomGroup::factory()->create(['property_id' => $group->property_id, 'name' => 'WING']);
})->throws(QueryException::class);

test('owners rename a group and only changed edits are logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $group = RoomGroup::factory()->for($property)->create(['name' => 'Floor 1']);
    $url = route('settings.room-groups.update', [$property->code, $group]);

    $this->actingAs($owner)
        ->put($url, ['name' => 'Ground floor'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Ground floor saved.']);

    expect($group->refresh()->name)->toBe('Ground floor');

    $this->put($url, ['name' => 'Ground floor'])->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'room_group.updated')->count())->toBe(1);
});

test('a group can keep its own name when saved', function () {
    $owner = User::factory()->owner()->create();
    $group = RoomGroup::factory()->for($owner->property)->create(['name' => 'Floor 1']);

    $this->actingAs($owner)
        ->put(route('settings.room-groups.update', [$owner->property->code, $group]), ['name' => 'floor 1'])
        ->assertSessionHasNoErrors();
});

test('reordering room groups sets positions and refuses other ids', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $a = RoomGroup::factory()->for($property)->create(['position' => 1]);
    $b = RoomGroup::factory()->for($property)->create(['position' => 2]);
    $theirs = RoomGroup::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.room-groups.reorder', $property->code), ['order' => [$b->id, $a->id]])
        ->assertSessionHasNoErrors();

    expect($b->refresh()->position)->toBe(1)->and($a->refresh()->position)->toBe(2);

    $this->put(route('settings.room-groups.reorder', $property->code), ['order' => [$theirs->id, $a->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The room groups changed. Reload the page and try again.']);

    expect(app(ReorderRoomGroups::class)($property, $owner, [$a->id]))->toBeFalse()
        ->and($b->refresh()->position)->toBe(1);
});

test('a group with active rooms cannot be deleted, an empty one can', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $group = RoomGroup::factory()->for($property)->create(['name' => 'Floor 1']);
    $room = Room::factory()->for($property)->create(['room_group_id' => $group->id]);
    $url = route('settings.room-groups.destroy', [$property->code, $group]);

    $this->actingAs($owner)
        ->delete($url)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Floor 1 still has rooms. Move or archive them first.']);

    expect(RoomGroup::query()->whereKey($group->id)->exists())->toBeTrue();

    $room->forceFill(['archived_at' => now()])->save();

    $this->delete($url)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Floor 1 deleted.']);

    expect(RoomGroup::query()->whereKey($group->id)->exists())->toBeFalse()
        ->and($room->refresh()->room_group_id)->toBeNull()
        ->and(Activity::query()->where('event', 'room_group.deleted')->count())->toBe(1);
});
