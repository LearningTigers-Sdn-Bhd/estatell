<?php

use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\RoomNumberGenerator;
use Spatie\Activitylog\Models\Activity;

test('range mode keeps zero padding and adds prefix and suffix', function () {
    $generator = new RoomNumberGenerator;

    expect(array_column($generator->generate('range', ['from' => '101', 'to' => '103']), 'number'))->toBe(['101', '102', '103'])
        ->and(array_column($generator->generate('range', ['from' => '08', 'to' => '11']), 'number'))->toBe(['08', '09', '10', '11'])
        ->and(array_column($generator->generate('range', ['from' => '1', 'to' => '2', 'prefix' => 'Villa ', 'suffix' => 'A']), 'number'))->toBe(['Villa 1A', 'Villa 2A'])
        ->and($generator->size('range', ['from' => '101', 'to' => '110']))->toBe(10);
});

test('floors mode builds one group per floor', function () {
    $generator = new RoomNumberGenerator;
    $input = ['floor_from' => '1', 'floor_to' => '2', 'room_from' => '01', 'room_to' => '03'];

    expect($generator->generate('floors', $input))->toBe([
        ['number' => '101', 'group' => 'Floor 1'],
        ['number' => '102', 'group' => 'Floor 1'],
        ['number' => '103', 'group' => 'Floor 1'],
        ['number' => '201', 'group' => 'Floor 2'],
        ['number' => '202', 'group' => 'Floor 2'],
        ['number' => '203', 'group' => 'Floor 2'],
    ])->and($generator->size('floors', $input))->toBe(6);
});

test('a range run creates rooms of one type in the chosen group', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    $group = RoomGroup::factory()->for($property)->create();
    Room::factory()->for($property)->create(['position' => 7]);

    $from = route('settings.room-types.rooms.edit', [$property->code, $type]);

    $this->actingAs($owner)
        ->from($from)
        ->post(route('settings.rooms.generate', $property->code), ['mode' => 'range', 'room_type_id' => $type->id, 'room_group_id' => $group->id, 'from' => '101', 'to' => '103'])
        ->assertRedirect($from)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '3 rooms created.']);

    $rooms = Room::query()->whereIn('number', ['101', '102', '103'])->orderBy('position')->get();

    expect($rooms->pluck('number')->all())->toBe(['101', '102', '103'])
        ->and($rooms->pluck('position')->all())->toBe([8, 9, 10])
        ->and($rooms->pluck('room_group_id')->unique()->all())->toBe([$group->id])
        ->and($rooms->pluck('room_type_id')->unique()->all())->toBe([$type->id])
        ->and(Activity::query()->where('event', 'room.generated')->sole()->properties['count'])->toBe(3);
});

test('a floors run reuses existing floor groups and creates missing ones', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    $existing = RoomGroup::factory()->for($property)->create(['name' => 'floor 1', 'position' => 1]);

    $this->actingAs($owner)
        ->post(route('settings.rooms.generate', $property->code), ['mode' => 'floors', 'room_type_id' => $type->id, 'floor_from' => 1, 'floor_to' => 2, 'room_from' => '01', 'room_to' => '02'])
        ->assertSessionHasNoErrors();

    $floor2 = RoomGroup::query()->where('name', 'Floor 2')->sole();

    expect(RoomGroup::query()->where('property_id', $property->id)->count())->toBe(2)
        ->and($floor2->position)->toBe(2)
        ->and(Room::query()->where('number', '102')->sole()->room_group_id)->toBe($existing->id)
        ->and(Room::query()->where('number', '201')->sole()->room_group_id)->toBe($floor2->id);
});

test('a run is all or nothing when a number is already used', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['number' => '102']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.generate', $property->code), ['mode' => 'range', 'room_type_id' => $type->id, 'from' => '101', 'to' => '103'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No rooms created. These numbers are already used: 102.']);

    expect(Room::query()->where('property_id', $property->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'room.generated')->count())->toBe(0);
});

test('numbers used only by archived rooms are free', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->archived()->create(['number' => '101']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.generate', $property->code), ['mode' => 'range', 'room_type_id' => $type->id, 'from' => '101', 'to' => '101'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 room created.']);
});

test('generator input is validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    $archived = RoomType::factory()->for($property)->archived()->create();
    $foreignGroup = RoomGroup::factory()->create();

    $payload = array_merge(['mode' => 'range', 'room_type_id' => $type->id, 'from' => '101', 'to' => '110'], array_map(
        function ($value) use ($archived, $foreignGroup) {
            return is_callable($value) ? $value(compact('archived', 'foreignGroup')) : $value;
        },
        $overrides,
    ));

    $this->actingAs($owner)
        ->post(route('settings.rooms.generate', $property->code), $payload)
        ->assertSessionHasErrors($field);

    expect(Room::query()->count())->toBe(0);
})->with([
    'unknown mode' => [['mode' => 'grid'], 'mode'],
    'archived room type' => [['room_type_id' => fn ($m) => $m['archived']->id], 'room_type_id'],
    'group of another property' => [['room_group_id' => fn ($m) => $m['foreignGroup']->id], 'room_group_id'],
    'digits only' => [['from' => '10a'], 'from'],
    'last below first' => [['from' => '110', 'to' => '101'], 'to'],
    'more than 200 rooms' => [['from' => '1', 'to' => '201'], 'to'],
    'floor out of range' => [['mode' => 'floors', 'floor_from' => 0, 'floor_to' => 2, 'room_from' => '01', 'room_to' => '02'], 'floor_from'],
    'last floor below first' => [['mode' => 'floors', 'floor_from' => 3, 'floor_to' => 2, 'room_from' => '01', 'room_to' => '02'], 'floor_to'],
    'floors run over 200 rooms' => [['mode' => 'floors', 'floor_from' => 1, 'floor_to' => 5, 'room_from' => '01', 'room_to' => '50'], 'room_to'],
    'prefix too long' => [['prefix' => str_repeat('a', 11)], 'prefix'],
]);

test('the preview lists the numbers and marks the taken ones', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $type = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['number' => '102']);
    Room::factory()->for($property)->archived()->create(['number' => '103']);
    Room::factory()->create(['number' => '101']);

    $this->actingAs($owner)
        ->postJson(route('settings.rooms.generate.preview', $property->code), ['mode' => 'range', 'room_type_id' => $type->id, 'from' => '101', 'to' => '103'])
        ->assertOk()
        ->assertExactJson(['rooms' => [
            ['number' => '101', 'group' => null, 'taken' => false],
            ['number' => '102', 'group' => null, 'taken' => true],
            ['number' => '103', 'group' => null, 'taken' => false],
        ]]);

    expect(Room::query()->where('property_id', $property->id)->count())->toBe(2);
});

test('the preview rejects invalid input', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->postJson(route('settings.rooms.generate.preview', $owner->property->code), ['mode' => 'range'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_type_id', 'from', 'to']);
});
