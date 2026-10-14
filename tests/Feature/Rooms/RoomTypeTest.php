<?php

use App\Actions\Rooms\ReorderRoomTypes;
use App\Enums\DefaultRoleEnum;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\RoomType;
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
        ->assertRedirect(route('settings.room-types.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Deluxe King created.']);

    $roomType = RoomType::query()->where('name', 'Deluxe King')->sole();

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
        ->assertRedirect(route('settings.room-types.index', $property->code))
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
