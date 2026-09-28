<?php

use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rooms\BedLabelGenerator;
use Spatie\Activitylog\Models\Activity;

function dorm(Property $property, string $number, array $attributes = []): Room
{
    return Room::factory()->for($property)->create([
        'room_type_id' => RoomType::factory()->for($property)->bed()->create()->id,
        'number' => $number,
        ...$attributes,
    ]);
}

test('labels use one letter per room', function () {
    $generator = new BedLabelGenerator;

    expect($generator->labels('A', 3))->toBe(['A1', 'A2', 'A3'])
        ->and($generator->labels('B', 2))->toBe(['B1', 'B2'])
        ->and($generator->labels('A', 0))->toBe([])
        ->and(array_map($generator->letter(...), [0, 25, 26, 27, 51, 52]))->toBe(['A', 'Z', 'AA', 'AB', 'AZ', 'BA']);
});

test('bulk generate gives every dorm room without beds its own letter', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $first = dorm($property, 'D1', ['position' => 1]);
    $second = dorm($property, 'D2', ['position' => 2]);
    $third = dorm($property, 'D3', ['position' => 3]);
    Bed::factory()->for($third)->create(['label' => 'X1']);
    Room::factory()->for($property)->create(['number' => '101']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.generate', $property->code), ['count' => 3])
        ->assertRedirect(route('settings.rooms.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '6 beds added.']);

    expect($first->beds()->ordered()->pluck('label')->all())->toBe(['A1', 'A2', 'A3'])
        ->and($second->beds()->ordered()->pluck('label')->all())->toBe(['B1', 'B2', 'B3'])
        ->and($third->beds()->pluck('label')->all())->toBe(['X1'])
        ->and(Bed::query()->count())->toBe(7)
        ->and(Activity::query()->where('event', 'bed.generated')->count())->toBe(1);
});

test('a picked room keeps its letter whichever rooms are picked', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    dorm($property, 'D1', ['position' => 1]);
    $second = dorm($property, 'D2', ['position' => 2]);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.generate', $property->code), ['count' => 2, 'room_ids' => [$second->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 beds added.']);

    expect($second->beds()->ordered()->pluck('label')->all())->toBe(['B1', 'B2'])
        ->and(Bed::query()->count())->toBe(2);
});

test('archived dorm rooms do not take a letter', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    dorm($property, 'D1', ['position' => 1, 'archived_at' => now()]);
    $second = dorm($property, 'D2', ['position' => 2]);

    $this->actingAs($owner)->post(route('settings.rooms.beds.generate', $property->code), ['count' => 1]);

    expect($second->beds()->pluck('label')->all())->toBe(['A1']);
});

test('adding again continues after the room\'s last number', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $first = dorm($property, 'D1', ['position' => 1]);
    $second = dorm($property, 'D2', ['position' => 2]);
    Bed::factory()->for($second)->create(['label' => 'b2', 'position' => 1]);
    Bed::factory()->for($second)->create(['label' => 'Top bunk', 'position' => 2]);
    Bed::factory()->for($second)->archived()->create(['label' => 'B9', 'position' => 3]);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.generate', $property->code), ['count' => 2, 'room_ids' => [$first->id, $second->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '4 beds added.']);

    expect($first->beds()->ordered()->pluck('label')->all())->toBe(['A1', 'A2'])
        ->and($second->beds()->active()->ordered()->pluck('label')->all())->toBe(['b2', 'Top bunk', 'B3', 'B4']);
});

test('the next number ignores labels that do not follow the room letter', function () {
    $generator = new BedLabelGenerator;

    expect($generator->nextNumber('A', []))->toBe(1)
        ->and($generator->nextNumber('A', ['A1', 'a4', 'B7', 'A-2', 'Top']))->toBe(5)
        ->and($generator->nextNumber('B', ['A1', 'B7']))->toBe(8)
        ->and($generator->nextNumber('Bunk.', ['Bunk.3']))->toBe(4)
        ->and($generator->labels('A', 2, 5))->toBe(['A5', 'A6']);
});

test('a prefix replaces the room letter', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dorm($property, 'D1');
    Bed::factory()->for($room)->create(['label' => 'A1', 'position' => 1]);

    $url = route('settings.rooms.beds.generate', $property->code);
    $this->actingAs($owner)->post($url, ['count' => 2, 'prefix' => ' Bunk ', 'room_ids' => [$room->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 beds added.']);
    $this->post($url, ['count' => 1, 'prefix' => 'Bunk', 'room_ids' => [$room->id]]);
    $this->post($url, ['count' => 1, 'prefix' => '', 'room_ids' => [$room->id]]);

    expect($room->beds()->ordered()->pluck('label')->all())->toBe(['A1', 'Bunk1', 'Bunk2', 'Bunk3', 'A2']);
});

test('the rooms page sends each dorm room its default prefix', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    dorm($property, 'D0', ['position' => 1, 'archived_at' => now()]);
    $first = dorm($property, 'D1', ['position' => 2]);
    $second = dorm($property, 'D2', ['position' => 3]);
    Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('settings.rooms.index', $property->code))
        ->assertInertia(fn ($page) => $page->where('bedPrefixes', [$first->id => 'A', $second->id => 'B']));
});

test('archived beds do not block adding', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = dorm($property, 'D1');
    Bed::factory()->for($room)->archived()->create(['label' => 'A1']);

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.generate', $property->code), ['count' => 1, 'room_ids' => [$room->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 bed added.']);

    expect($room->beds()->active()->pluck('label')->all())->toBe(['A1']);
});

test('bulk generate says so when every dorm room already has beds', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Bed::factory()->for(dorm($property, 'D1'))->create();

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.generate', $property->code), ['count' => 2])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No beds added. Every dorm room already has beds.']);

    expect(Bed::query()->count())->toBe(1);
});

test('generator input is validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $dorm = dorm($property, 'D1');
    $archived = dorm($property, 'D2', ['archived_at' => now()]);
    $sellsRooms = Room::factory()->for($property)->create();
    $foreign = dorm(Property::factory()->create(), 'D9');

    $payload = array_merge(['count' => 2, 'room_ids' => [$dorm->id]], array_map(
        function ($value) use ($archived, $sellsRooms, $foreign, $dorm) {
            return is_callable($value) ? $value(compact('archived', 'sellsRooms', 'foreign', 'dorm')) : $value;
        },
        $overrides,
    ));

    $this->actingAs($owner)
        ->post(route('settings.rooms.beds.generate', $property->code), $payload)
        ->assertSessionHasErrors($field);

    expect(Bed::query()->count())->toBe(0);
})->with([
    'count is required' => [['count' => ''], 'count'],
    'count is at least 1' => [['count' => 0], 'count'],
    'count is at most 50' => [['count' => 51], 'count'],
    'prefix is at most 10 characters' => [['prefix' => str_repeat('a', 11)], 'prefix'],
    'rooms must exist' => [['room_ids' => [0]], 'room_ids.0'],
    'archived room' => [['room_ids' => fn ($m) => [$m['archived']->id]], 'room_ids.0'],
    'room that sells rooms' => [['room_ids' => fn ($m) => [$m['sellsRooms']->id]], 'room_ids.0'],
    'room of another property' => [['room_ids' => fn ($m) => [$m['foreign']->id]], 'room_ids.0'],
    'duplicate rooms' => [['room_ids' => fn ($m) => [$m['dorm']->id, $m['dorm']->id]], 'room_ids.0'],
]);
