<?php

use App\Enums\DefaultRoleEnum;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function dormRoom(Property $property, array $attributes = []): Room
{
    return Room::factory()->for($property)->create([
        'room_type_id' => RoomType::factory()->for($property)->bed()->create()->id,
        ...$attributes,
    ]);
}

test('the rooms page lists the property\'s beds only, in order', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    Bed::factory()->for($room)->create(['label' => 'A2', 'position' => 2]);
    Bed::factory()->for($room)->create(['label' => 'A1', 'position' => 1]);
    Bed::factory()->create(['label' => 'Z9']);

    $this->actingAs($owner)
        ->get(route('settings.rooms.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rooms/index')
            ->has('beds', 2)
            ->where('beds.0.label', 'A1')
            ->where('beds.1.roomId', $room->id)
            ->where('bedLimit', 50));
});

test('owners add a bed to a dorm room', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    Bed::factory()->for($room)->create(['position' => 4]);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.store', [$property->code, $room]), ['label' => ' B1 '])
        ->assertRedirect(route('settings.rooms.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Bed B1 created.']);

    $bed = Bed::query()->where('label', 'B1')->sole();

    expect($bed->room_id)->toBe($room->id)
        ->and($bed->position)->toBe(5)
        ->and(Activity::query()->where('event', 'bed.created')->count())->toBe(1);
});

test('a room whose type sells rooms cannot have beds', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.store', [$property->code, $room]), ['label' => 'A1'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room '.$room->number.' cannot have beds. It is archived or its room type sells rooms.']);

    expect(Bed::query()->count())->toBe(0);
});

test('an archived room cannot get beds', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property, ['archived_at' => now()]);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.store', [$property->code, $room]), ['label' => 'A1'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room '.$room->number.' cannot have beds. It is archived or its room type sells rooms.']);

    expect(Bed::query()->count())->toBe(0);
});

test('bed labels are validated', function (string $label) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    Bed::factory()->for($room)->create(['label' => 'A1']);
    Bed::factory()->for($room)->archived()->create(['label' => 'A2']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.store', [$property->code, $room]), ['label' => $label])
        ->assertSessionHasErrors('label');

    expect($room->beds()->count())->toBe(2);
})->with([
    'required' => [''],
    'unique in the room, ignoring case' => ['a1'],
    'at most 30 characters' => [str_repeat('a', 31)],
]);

test('an archived bed frees its label and another room may reuse a label', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    Bed::factory()->for($room)->archived()->create(['label' => 'A1']);
    Bed::factory()->for(dormRoom($property))->create(['label' => 'A2']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.store', [$property->code, $room]), ['label' => 'A1'])
        ->assertSessionHasNoErrors();
    $this->post(route('settings.rooms.beds.store', [$property->code, $room]), ['label' => 'A2'])
        ->assertSessionHasNoErrors();

    expect($room->beds()->active()->count())->toBe(2);
});

test('the database rejects duplicate active labels and blank labels', function () {
    $room = dormRoom(Property::factory()->create());
    Bed::factory()->for($room)->create(['label' => 'A1']);

    expect(fn () => Bed::factory()->for($room)->create(['label' => 'a1']))->toThrow(QueryException::class)
        ->and(fn () => Bed::factory()->for($room)->create(['label' => ' ']))->toThrow(QueryException::class);
});

test('owners rename a bed and only changed edits are logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    $bed = Bed::factory()->for($room)->create(['label' => 'A1']);
    $url = route('settings.rooms.beds.update', [$property->code, $room, $bed]);

    $this->actingAs($owner)->put($url, ['label' => 'Top A1'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Bed Top A1 saved.']);
    $this->put($url, ['label' => 'Top A1'])->assertSessionHasNoErrors();

    expect($bed->refresh()->label)->toBe('Top A1')
        ->and(Activity::query()->where('event', 'bed.updated')->count())->toBe(1);
});

test('an archived bed cannot be edited', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    $bed = Bed::factory()->for($room)->archived()->create(['label' => 'A1']);

    $this->actingAs($owner)
        ->put(route('settings.rooms.beds.update', [$property->code, $room, $bed]), ['label' => 'B1'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Bed A1 is archived. Restore it before editing.']);

    expect($bed->refresh()->label)->toBe('A1');
});

test('archiving and restoring a bed', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    $bed = Bed::factory()->for($room)->create(['label' => 'A1']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.archive', [$property->code, $room, $bed]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Bed A1 archived.']);

    expect($bed->refresh()->archived_at)->not->toBeNull();

    $this->post(route('settings.rooms.beds.archive', [$property->code, $room, $bed]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Bed A1 is already archived.']);

    $this->post(route('settings.rooms.beds.restore', [$property->code, $room, $bed]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Bed A1 restored.']);

    expect($bed->refresh()->archived_at)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['bed.archived', 'bed.restored'])->count())->toBe(2);
});

test('a bed cannot be restored while an active bed uses its label or its room is archived', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    $old = Bed::factory()->for($room)->archived()->create(['label' => 'A1']);
    Bed::factory()->for($room)->create(['label' => 'A1']);
    $archivedRoom = dormRoom($property, ['archived_at' => now()]);
    $inArchivedRoom = Bed::factory()->for($archivedRoom)->archived()->create(['label' => 'B1']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.restore', [$property->code, $room, $old]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Bed A1 cannot be restored. Another bed in the room uses the same label.']);
    $this->post(route('settings.rooms.beds.restore', [$property->code, $archivedRoom, $inArchivedRoom]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Bed B1 cannot be restored. Another bed in the room uses the same label.']);

    expect($old->refresh()->archived_at)->not->toBeNull()
        ->and($inArchivedRoom->refresh()->archived_at)->not->toBeNull();
});

test('a room with beds cannot move to a room type that sells rooms', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property, ['number' => '201']);
    Bed::factory()->for($room)->create();
    $sellsRooms = RoomType::factory()->for($property)->create();
    $otherDorm = RoomType::factory()->for($property)->bed()->create();
    $url = route('settings.rooms.update', [$property->code, $room]);

    $this->actingAs($owner)->put($url, ['number' => '201', 'room_type_id' => $sellsRooms->id])
        ->assertSessionHasErrors('room_type_id');
    expect($room->refresh()->room_type_id)->not->toBe($sellsRooms->id);

    $this->put($url, ['number' => '201', 'room_type_id' => $otherDorm->id])->assertSessionHasNoErrors();
    expect($room->refresh()->room_type_id)->toBe($otherDorm->id);
});

test('a room without beds may change room type freely', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property, ['number' => '201']);
    $sellsRooms = RoomType::factory()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('settings.rooms.update', [$property->code, $room]), ['number' => '201', 'room_type_id' => $sellsRooms->id])
        ->assertSessionHasNoErrors();

    expect($room->refresh()->room_type_id)->toBe($sellsRooms->id);
});

test('archiving a room leaves its beds as they are', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dormRoom($property);
    $bed = Bed::factory()->for($room)->create();

    $this->actingAs($owner)->post(route('settings.rooms.archive', [$property->code, $room]));

    expect($bed->refresh()->archived_at)->toBeNull();
});

test('changing beds needs rooms.manage', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $room = dormRoom($frontDesk->property);
    $bed = Bed::factory()->for($room)->create();

    $this->actingAs($frontDesk)->post(route('settings.rooms.beds.store', [$code, $room]), [])->assertForbidden();
    $this->put(route('settings.rooms.beds.update', [$code, $room, $bed]), [])->assertForbidden();
    $this->post(route('settings.rooms.beds.archive', [$code, $room, $bed]))->assertForbidden();
    $this->post(route('settings.rooms.beds.restore', [$code, $room, $bed]))->assertForbidden();
    $this->post(route('settings.rooms.beds.generate', $code), [])->assertForbidden();
});

test('another property\'s rooms and beds are not reachable', function () {
    $owner = User::factory()->owner()->create();
    $other = Property::factory()->create();
    $foreignRoom = dormRoom($other);
    $foreignBed = Bed::factory()->for($foreignRoom)->create();
    $ownRoom = dormRoom($owner->property);
    $code = $owner->property->code;

    $this->actingAs($owner)->post(route('settings.rooms.beds.store', [$code, $foreignRoom]), ['label' => 'A1'])->assertNotFound();
    $this->put(route('settings.rooms.beds.update', [$code, $foreignRoom, $foreignBed]), ['label' => 'x'])->assertNotFound();
    $this->put(route('settings.rooms.beds.update', [$code, $ownRoom, $foreignBed]), ['label' => 'x'])->assertNotFound();
    $this->post(route('settings.rooms.beds.archive', [$code, $ownRoom, $foreignBed]))->assertNotFound();

    expect($foreignBed->refresh()->archived_at)->toBeNull()->and($foreignBed->label)->not->toBe('x');
});
