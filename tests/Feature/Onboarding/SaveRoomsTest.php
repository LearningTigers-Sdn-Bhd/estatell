<?php

use App\Enums\SellUnit;
use App\Enums\StepState;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Onboarding\Checks\RoomsCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    $this->standard = RoomType::factory()->for($this->property)->create(['name' => 'Standard', 'position' => 1]);
    $this->deluxe = RoomType::factory()->for($this->property)->create(['name' => 'Deluxe', 'position' => 2]);
    $this->save = fn (array $payload) => $this->actingAs($this->owner)
        ->put(route('onboarding.rooms.update', $this->property->code), $payload);
});

function roomsStepRow(string $numbers, array $overrides = []): array
{
    return ['key' => 'row-'.md5($numbers.json_encode($overrides)), 'id' => null, 'name' => '', 'numbers' => $numbers, ...$overrides];
}

/**
 * @param  array<string, RoomType>  $types  Room type of each number.
 */
function roomsPayload(array $rows, array $types, bool $useGroups = false, array $bedsPerRoom = []): array
{
    return [
        'use_groups' => $useGroups,
        'groups' => $rows,
        'types' => array_map(fn (int|string $number, RoomType $type): array => ['number' => (string) $number, 'room_type_id' => $type->id], array_keys($types), $types),
        'beds_per_room' => $bedsPerRoom,
    ];
}

/**
 * @param  list<string>  $numbers
 * @return array<string, RoomType>
 */
function typedAs(RoomType $type, array $numbers): array
{
    return array_fill_keys($numbers, $type);
}

test('the check is not started, in progress or done by room types with rooms and dorm rooms with beds', function () {
    $check = new RoomsCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    Room::factory()->for($this->property)->for($this->standard)->create();
    expect($check->state($this->property))->toBe(StepState::InProgress);

    Room::factory()->for($this->property)->for($this->deluxe)->create();
    expect($check->state($this->property))->toBe(StepState::Done);

    $dorm = RoomType::factory()->for($this->property)->bed()->create();
    expect($check->state($this->property))->toBe(StepState::InProgress);

    $dormRoom = Room::factory()->for($this->property)->for($dorm)->create();
    expect($check->state($this->property))->toBe(StepState::InProgress);

    Bed::factory()->for($dormRoom)->create();
    expect($check->state($this->property))->toBe(StepState::Done);
});

test('an archived room does not count for the check', function () {
    Room::factory()->for($this->property)->for($this->standard)->archived()->create();
    Room::factory()->for($this->property)->for($this->deluxe)->create();

    expect((new RoomsCheck)->state($this->property))->toBe(StepState::InProgress);
});

test('numbers create rooms in typed order with their types and move to the next step', function () {
    ($this->save)(roomsPayload(
        [roomsStepRow('103, 101-102, G1-G2')],
        typedAs($this->standard, ['103', '101', '102']) + typedAs($this->deluxe, ['G1', 'G2']),
    ))->assertSessionHasNoErrors()->assertRedirect(route('onboarding.taxes.edit', $this->property->code));

    expect($this->property->rooms()->ordered()->pluck('number')->all())->toBe(['103', '101', '102', 'G1', 'G2'])
        ->and($this->property->rooms()->where('room_type_id', $this->deluxe->id)->count())->toBe(2)
        ->and($this->property->rooms()->whereNotNull('room_group_id')->count())->toBe(0)
        ->and((new RoomsCheck)->state($this->property))->toBe(StepState::Done);
});

test('a fully empty row is ignored and at least one number is needed', function () {
    ($this->save)(roomsPayload([roomsStepRow('')], []))->assertSessionHasErrors('groups.0.numbers');

    ($this->save)(roomsPayload(
        [roomsStepRow('Ground', ['name' => 'Ground', 'numbers' => '101']), roomsStepRow('', ['name' => ''])],
        typedAs($this->standard, ['101']),
        useGroups: true,
    ))->assertSessionHasNoErrors();

    expect($this->property->rooms()->count())->toBe(1)
        ->and($this->property->roomGroups()->pluck('name')->all())->toBe(['Ground']);
});

test('every number needs a room type of this property', function () {
    $foreign = RoomType::factory()->for(Property::factory())->create();

    ($this->save)(roomsPayload([roomsStepRow('101-102')], typedAs($this->standard, ['101'])))
        ->assertSessionHasErrors(['groups.0.numbers' => 'Pick a room type for 102.']);
    ($this->save)(roomsPayload([roomsStepRow('101')], typedAs($foreign, ['101'])))
        ->assertSessionHasErrors('types.0.room_type_id');

    expect($this->property->rooms()->count())->toBe(0);
});

test('groups are created in table order and each row goes into its group', function () {
    ($this->save)(roomsPayload([
        roomsStepRow('101-102', ['name' => 'Ground floor']),
        roomsStepRow('201', ['name' => 'Garden block']),
    ], typedAs($this->standard, ['101', '102']) + typedAs($this->deluxe, ['201']), useGroups: true))->assertSessionHasNoErrors();

    $groups = $this->property->roomGroups()->orderBy('position')->get();

    expect($groups->pluck('name')->all())->toBe(['Ground floor', 'Garden block'])
        ->and($this->property->rooms()->where('room_group_id', $groups[0]->id)->pluck('number')->all())->toBe(['101', '102'])
        ->and($this->property->rooms()->where('room_group_id', $groups[1]->id)->pluck('number')->all())->toBe(['201']);
});

test('with groups a row with numbers needs a name, and names differ ignoring case', function () {
    ($this->save)(roomsPayload([roomsStepRow('101')], typedAs($this->standard, ['101']), useGroups: true))
        ->assertSessionHasErrors(['groups.0.name' => 'Enter a group name.']);

    ($this->save)(roomsPayload([
        roomsStepRow('101', ['name' => 'Block A']),
        roomsStepRow('102', ['name' => 'block a']),
    ], typedAs($this->standard, ['101', '102']), useGroups: true))->assertSessionHasErrors('groups.1.name');

    expect($this->property->roomGroups()->count())->toBe(0);
});

test('saved groups are renamed but cannot swap names, and keep using groups', function () {
    $first = RoomGroup::factory()->for($this->property)->create(['name' => 'A', 'position' => 1]);
    $second = RoomGroup::factory()->for($this->property)->create(['name' => 'B', 'position' => 2]);

    ($this->save)(roomsPayload([
        roomsStepRow('101', ['id' => $first->id, 'name' => 'B']),
        roomsStepRow('201', ['id' => $second->id, 'name' => 'A']),
    ], typedAs($this->standard, ['101', '201']), useGroups: true))->assertSessionHasErrors(['groups.0.name', 'groups.1.name']);

    ($this->save)(roomsPayload([roomsStepRow('101')], typedAs($this->standard, ['101']), useGroups: false))
        ->assertSessionHasErrors('groups.0.name');

    ($this->save)(roomsPayload([
        roomsStepRow('201', ['id' => $second->id, 'name' => 'Upper']),
        roomsStepRow('101', ['id' => $first->id, 'name' => 'A']),
    ], typedAs($this->standard, ['101', '201']), useGroups: false))->assertSessionHasNoErrors();

    expect($second->refresh()->only(['name', 'position']))->toBe(['name' => 'Upper', 'position' => 1])
        ->and($first->refresh()->position)->toBe(2)
        ->and($this->property->rooms()->where('room_group_id', $second->id)->pluck('number')->all())->toBe(['201']);
});

test('a group id of another property is rejected', function () {
    $foreign = RoomGroup::factory()->for(Property::factory())->create();

    ($this->save)(roomsPayload([roomsStepRow('101', ['id' => $foreign->id, 'name' => $foreign->name])], typedAs($this->standard, ['101']), useGroups: true))
        ->assertSessionHasErrors('groups.0.id');
});

test('a row that does not parse shows its error and saves nothing', function () {
    ($this->save)(roomsPayload([roomsStepRow('110-101')], []))
        ->assertSessionHasErrors(['groups.0.numbers' => '"110-101" runs backwards. Put the smaller number first.']);
    ($this->save)(roomsPayload([roomsStepRow('1-201')], []))
        ->assertSessionHasErrors(['groups.0.numbers' => 'That is 201 rooms. One group takes at most 200.']);
    ($this->save)(roomsPayload([
        roomsStepRow('201-203', ['name' => 'One']),
        roomsStepRow('203-205', ['name' => 'Two']),
    ], typedAs($this->standard, ['201', '202', '203', '204', '205']), useGroups: true))
        ->assertSessionHasErrors(['groups.1.numbers' => '203 is listed twice. Each number can be used once.']);

    expect($this->property->rooms()->count())->toBe(0);
});

test('saved rooms keep their id and take a new type or group, and a removed number is archived', function () {
    $group = RoomGroup::factory()->for($this->property)->create(['name' => 'Ground']);
    $kept = Room::factory()->for($this->property)->for($this->standard)->create(['number' => '101', 'room_group_id' => $group->id]);
    $removed = Room::factory()->for($this->property)->for($this->standard)->create(['number' => '102', 'room_group_id' => $group->id]);

    ($this->save)(roomsPayload([
        roomsStepRow('', ['id' => $group->id, 'name' => 'Ground']),
        roomsStepRow('101, 201', ['name' => 'Upper']),
    ], typedAs($this->deluxe, ['101']) + typedAs($this->standard, ['201']), useGroups: true))->assertSessionHasNoErrors();

    $upper = $this->property->roomGroups()->where('name', 'Upper')->sole();

    expect($kept->refresh()->only(['room_type_id', 'room_group_id', 'archived_at']))->toBe(['room_type_id' => $this->deluxe->id, 'room_group_id' => $upper->id, 'archived_at' => null])
        ->and($removed->refresh()->archived_at)->not->toBeNull()
        ->and($group->refresh()->exists)->toBeTrue()
        ->and($this->property->rooms()->active()->ordered()->pluck('number')->all())->toBe(['101', '201']);
});

test('an archived room frees its number', function () {
    Room::factory()->for($this->property)->for($this->standard)->archived()->create(['number' => '101']);

    ($this->save)(roomsPayload([roomsStepRow('101')], typedAs($this->standard, ['101'])))->assertSessionHasNoErrors();

    expect($this->property->rooms()->active()->pluck('number')->all())->toBe(['101']);
});

test('a saved room with beds takes only a type sold by bed', function () {
    $dorm = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Dorm']);
    $otherDorm = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Other Dorm']);
    $room = Room::factory()->for($this->property)->for($dorm)->create(['number' => '301']);
    Bed::factory()->for($room)->create();

    ($this->save)(roomsPayload([roomsStepRow('301')], typedAs($this->standard, ['301'])))
        ->assertSessionHasErrors(['groups.0.numbers' => '301 has beds. Pick a type sold by bed.']);

    expect($room->refresh()->room_type_id)->toBe($dorm->id);

    ($this->save)(roomsPayload([roomsStepRow('301')], typedAs($otherDorm, ['301'])))->assertSessionHasNoErrors();

    expect($room->refresh()->room_type_id)->toBe($otherDorm->id);
});

test('a saved room with stays assigned cannot change type', function () {
    $room = Room::factory()->for($this->property)->for($this->standard)->create(['number' => '305']);
    UnitNight::factory()->create(['room_id' => $room->id, 'date' => '2026-10-20']);

    ($this->save)(roomsPayload([roomsStepRow('305')], typedAs($this->deluxe, ['305'])))
        ->assertSessionHasErrors(['groups.0.numbers' => '305 has stays assigned from 20 Oct. Move them first.']);
    ($this->save)(roomsPayload([roomsStepRow('305')], typedAs($this->standard, ['305'])))->assertSessionHasNoErrors();

    expect($room->refresh()->room_type_id)->toBe($this->standard->id);
});

test('dorm rooms get beds per room once, and the count is needed only for rooms without beds', function () {
    $dorm = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Dorm', 'position' => 3]);

    ($this->save)(roomsPayload([roomsStepRow('301-302')], typedAs($dorm, ['301', '302']), bedsPerRoom: [$dorm->id => 0]))
        ->assertSessionHasErrors(["beds_per_room.{$dorm->id}" => 'Enter 1 to 50 beds.']);

    ($this->save)(roomsPayload([roomsStepRow('301-302')], typedAs($dorm, ['301', '302']), bedsPerRoom: [$dorm->id => 6]))
        ->assertSessionHasNoErrors();

    $rooms = $this->property->rooms()->ordered()->withCount('beds')->get();

    expect($rooms->pluck('beds_count', 'number')->all())->toBe(['301' => 6, '302' => 6])
        ->and(Bed::query()->whereIn('room_id', $rooms->pluck('id'))->orderBy('id')->limit(2)->pluck('label')->all())->toBe(['A1', 'A2']);

    ($this->save)(roomsPayload([roomsStepRow('301-302')], typedAs($dorm, ['301', '302'])))->assertSessionHasNoErrors();
    ($this->save)(roomsPayload([roomsStepRow('301-303')], typedAs($dorm, ['301', '302', '303']), bedsPerRoom: [$dorm->id => 2]))->assertSessionHasNoErrors();

    expect($this->property->rooms()->ordered()->withCount('beds')->pluck('beds_count', 'number')->all())->toBe(['301' => 6, '302' => 6, '303' => 2]);
});

test('the preview returns the numbers of each row, or its error', function () {
    $this->actingAs($this->owner)
        ->postJson(route('onboarding.rooms.preview', $this->property->code), ['rows' => [
            ['numbers' => '101-103, Villa 3'],
            ['numbers' => '134-102'],
            ['numbers' => '103, 104'],
            ['numbers' => ''],
        ]])
        ->assertOk()
        ->assertExactJson(['rows' => [
            ['numbers' => ['101', '102', '103', 'Villa 3'], 'repeated' => [], 'error' => null],
            ['numbers' => [], 'repeated' => [], 'error' => '"134-102" runs backwards. Put the smaller number first.'],
            ['numbers' => ['103', '104'], 'repeated' => ['103'], 'error' => '103 is listed twice. Each number can be used once.'],
            ['numbers' => [], 'repeated' => [], 'error' => null],
        ]]);
});

test('the save is refused while room types are not done', function () {
    $this->property->roomTypes()->update(['archived_at' => now()]);

    ($this->save)(roomsPayload([roomsStepRow('101')], []))->assertSessionHasErrors('onboarding');
});

test('the page sends every room type, the group rows as ranges and the saved rooms', function () {
    $dorm = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Dorm', 'position' => 3]);
    $group = RoomGroup::factory()->for($this->property)->create(['name' => 'Wing']);

    foreach (['101', '102', '103', '105'] as $number) {
        Room::factory()->for($this->property)->for($this->standard)->create(['number' => $number, 'room_group_id' => $group->id]);
    }

    $dormRoom = Room::factory()->for($this->property)->for($dorm)->create(['number' => '301']);
    Bed::factory()->count(6)->for($dormRoom)->sequence(fn ($sequence) => ['label' => 'A'.($sequence->index + 1)])->create();

    $this->actingAs($this->owner)->get(route('onboarding.rooms.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/rooms')
            ->has('roomTypes', 3)
            ->where('roomTypes.2.sellUnit', SellUnit::Bed->value)
            ->where('roomTypes.2.bedsPerRoom', 6)
            ->where('roomTypes.0.bedsPerRoom', 4)
            ->where('groups', [
                ['key' => "group-{$group->id}", 'id' => $group->id, 'name' => 'Wing', 'numbers' => '101-103, 105'],
                ['key' => 'ungrouped', 'id' => null, 'name' => '', 'numbers' => '301'],
            ])
            ->has('rooms', 5)
            ->where('rooms.4', ['number' => '301', 'roomTypeId' => $dorm->id, 'beds' => 6])
            ->where('hasSavedGroups', true)
            ->where('locked', false));
});

test('the page opens as a preview while room types are not done', function () {
    $this->property->roomTypes()->update(['archived_at' => now()]);

    $this->actingAs($this->owner)->get(route('onboarding.rooms.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', 'Room types'));
});

test('another property cannot reach the rooms routes', function () {
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($other)->put(route('onboarding.rooms.update', $this->property->code), roomsPayload([roomsStepRow('101')], typedAs($this->standard, ['101'])))
        ->assertNotFound();
    $this->actingAs($other)->postJson(route('onboarding.rooms.preview', $this->property->code), ['rows' => [['numbers' => '101']]])
        ->assertNotFound();

    expect($this->property->rooms()->count())->toBe(0);
});
