<?php

use App\Actions\Onboarding\SaveOnboardingRooms;
use App\Enums\StepState;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\RoomType;
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

function roomRange(RoomType $type, string $from, string $to, array $overrides = []): array
{
    return ['room_type_id' => $type->id, 'group_key' => null, 'prefix' => '', 'from' => $from, 'to' => $to, ...$overrides];
}

function roomsPayload(array $ranges, array $groups = []): array
{
    return ['use_groups' => $groups !== [], 'groups' => $groups, 'ranges' => $ranges];
}

test('the check is not started, in progress or done by how many room types have rooms', function () {
    $check = new RoomsCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    Room::factory()->for($this->property)->for($this->standard)->create();
    expect($check->state($this->property))->toBe(StepState::InProgress);

    Room::factory()->for($this->property)->for($this->deluxe)->create();
    expect($check->state($this->property))->toBe(StepState::Done);

    RoomType::factory()->for($this->property)->bed()->create();
    expect($check->state($this->property))->toBe(StepState::Done);
});

test('an archived room does not count for the check', function () {
    Room::factory()->for($this->property)->for($this->standard)->archived()->create();
    Room::factory()->for($this->property)->for($this->deluxe)->create();

    expect((new RoomsCheck)->state($this->property))->toBe(StepState::InProgress);
});

test('ranges create rooms with the prefix and move to the next step', function () {
    ($this->save)(roomsPayload([
        roomRange($this->standard, '101', '103'),
        roomRange($this->deluxe, '1', '2', ['prefix' => 'G']),
    ]))->assertSessionHasNoErrors()->assertRedirect(route('onboarding.taxes.edit', $this->property->code));

    expect($this->property->rooms()->orderBy('id')->pluck('number')->all())->toBe(['101', '102', '103', 'G1', 'G2'])
        ->and($this->property->rooms()->where('room_type_id', $this->deluxe->id)->count())->toBe(2)
        ->and($this->property->roomGroups()->count())->toBe(0)
        ->and((new RoomsCheck)->state($this->property))->toBe(StepState::Done);
});

test('a fully empty row is ignored', function () {
    ($this->save)(roomsPayload([
        roomRange($this->standard, '101', '102'),
        roomRange($this->deluxe, '', ''),
    ]))->assertSessionHasNoErrors();

    expect($this->property->rooms()->count())->toBe(2);
});

test('nothing to add is refused while the property has no rooms', function () {
    ($this->save)(roomsPayload([roomRange($this->standard, '', '')]))->assertSessionHasErrors('ranges');

    expect($this->property->rooms()->count())->toBe(0);

    Room::factory()->for($this->property)->for($this->standard)->create();

    ($this->save)(roomsPayload([roomRange($this->standard, '', '')]))->assertSessionHasNoErrors();
});

test('groups are created in table order and each range goes into its group', function () {
    ($this->save)(roomsPayload([
        roomRange($this->standard, '101', '102', ['group_key' => 'a']),
        roomRange($this->deluxe, '201', '201', ['group_key' => 'b']),
    ], [
        ['key' => 'a', 'id' => null, 'name' => 'Ground floor'],
        ['key' => 'b', 'id' => null, 'name' => 'Garden block'],
    ]))->assertSessionHasNoErrors();

    $groups = $this->property->roomGroups()->orderBy('position')->get();

    expect($groups->pluck('name')->all())->toBe(['Ground floor', 'Garden block'])
        ->and($this->property->rooms()->where('room_group_id', $groups[0]->id)->count())->toBe(2)
        ->and($this->property->rooms()->where('room_group_id', $groups[1]->id)->pluck('number')->all())->toBe(['201']);
});

test('use groups needs a group on every range and at least one group', function () {
    ($this->save)(roomsPayload([roomRange($this->standard, '101', '102')], [
        ['key' => 'a', 'id' => null, 'name' => 'Ground floor'],
    ]))->assertSessionHasErrors('ranges.0.group_key');

    ($this->save)(['use_groups' => true, 'groups' => [], 'ranges' => [roomRange($this->standard, '101', '102')]])
        ->assertSessionHasErrors('groups');

    expect($this->property->rooms()->count())->toBe(0);
});

test('group names must differ ignoring case', function () {
    ($this->save)(roomsPayload([roomRange($this->standard, '101', '102', ['group_key' => 'a'])], [
        ['key' => 'a', 'id' => null, 'name' => 'Block A'],
        ['key' => 'b', 'id' => null, 'name' => 'block a'],
    ]))->assertSessionHasErrors('groups.1.name');

    expect($this->property->roomGroups()->count())->toBe(0);
});

test('a property with saved groups keeps using groups', function () {
    $group = RoomGroup::factory()->for($this->property)->create(['name' => 'Wing']);

    ($this->save)(['use_groups' => false, 'groups' => [], 'ranges' => [roomRange($this->standard, '101', '102')]])
        ->assertSessionHasErrors('groups');

    ($this->save)(roomsPayload([roomRange($this->standard, '101', '102', ['group_key' => 'wing'])], [
        ['key' => 'wing', 'id' => $group->id, 'name' => 'Wing'],
    ]))->assertSessionHasNoErrors();

    expect($this->property->rooms()->where('room_group_id', $group->id)->count())->toBe(2);
});

test('a group id of another property is rejected', function () {
    $foreign = RoomGroup::factory()->for(Property::factory())->create();

    ($this->save)(roomsPayload([roomRange($this->standard, '101', '102', ['group_key' => 'x'])], [
        ['key' => 'x', 'id' => $foreign->id, 'name' => $foreign->name],
    ]))->assertSessionHasErrors('groups.0.id');
});

test('a number in use or repeated across rows saves nothing', function () {
    Room::factory()->for($this->property)->for($this->standard)->create(['number' => '102']);

    ($this->save)(roomsPayload([
        roomRange($this->standard, '101', '103'),
    ]))->assertSessionHasErrors('ranges.0.from');

    ($this->save)(roomsPayload([
        roomRange($this->standard, '201', '203'),
        roomRange($this->deluxe, '203', '205'),
    ]))->assertSessionHasErrors('ranges.1.from');

    expect($this->property->rooms()->count())->toBe(1);
});

test('an archived room frees its number', function () {
    Room::factory()->for($this->property)->for($this->standard)->archived()->create(['number' => '101']);

    ($this->save)(roomsPayload([roomRange($this->standard, '101', '101')]))->assertSessionHasNoErrors();

    expect($this->property->rooms()->active()->pluck('number')->all())->toBe(['101']);
});

test('a range must be in order and within the limit', function () {
    ($this->save)(roomsPayload([roomRange($this->standard, '110', '101')]))->assertSessionHasErrors('ranges.0.to');
    ($this->save)(roomsPayload([roomRange($this->standard, '1', '201')]))->assertSessionHasErrors('ranges.0.to');
    ($this->save)(roomsPayload([roomRange($this->standard, '1', 'x')]))->assertSessionHasErrors('ranges.0.to');
});

test('a room type sold by bed or from another property is rejected', function () {
    $bed = RoomType::factory()->for($this->property)->bed()->create();
    $foreign = RoomType::factory()->for(Property::factory())->create();

    ($this->save)(roomsPayload([roomRange($bed, '1', '2')]))->assertSessionHasErrors('ranges.0.room_type_id');
    ($this->save)(roomsPayload([roomRange($foreign, '1', '2')]))->assertSessionHasErrors('ranges.0.room_type_id');
});

test('the action rolls back and returns the numbers when one is taken at save time', function () {
    Room::factory()->for($this->property)->for($this->standard)->create(['number' => '202']);

    $result = app(SaveOnboardingRooms::class)($this->property, $this->owner, [], [
        roomRange($this->standard, '101', '102') + ['suffix' => ''],
        roomRange($this->deluxe, '201', '203') + ['suffix' => ''],
    ]);

    expect($result)->toBe(['created' => 0, 'conflicts' => ['202']])
        ->and($this->property->rooms()->count())->toBe(1);
});

test('the save is refused while room types are not done', function () {
    $this->property->roomTypes()->update(['archived_at' => now()]);

    ($this->save)(roomsPayload([roomRange($this->standard, '101', '102')]))->assertSessionHasErrors('onboarding');
});

test('the page lists room types sold by room, saved groups and saved rooms', function () {
    RoomType::factory()->for($this->property)->bed()->create(['name' => 'Dorm']);
    $group = RoomGroup::factory()->for($this->property)->create(['name' => 'Wing']);
    Room::factory()->for($this->property)->for($this->standard)->create(['number' => '101', 'room_group_id' => $group->id]);

    $this->actingAs($this->owner)->get(route('onboarding.rooms.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/rooms')
            ->has('roomTypes', 2)
            ->has('groups', 1)
            ->has('rooms', 1)
            ->where('locked', false));
});

test('the page opens as a preview while room types are not done', function () {
    $this->property->roomTypes()->update(['archived_at' => now()]);

    $this->actingAs($this->owner)->get(route('onboarding.rooms.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', 'Room types'));
});

test('removing a saved room archives it', function () {
    $room = Room::factory()->for($this->property)->for($this->standard)->create(['number' => '101']);

    $this->actingAs($this->owner)->delete(route('onboarding.rooms.destroy', [$this->property->code, $room]))
        ->assertRedirect();

    expect($room->refresh()->archived_at)->not->toBeNull();
});

test('another property cannot reach the rooms routes', function () {
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $room = Room::factory()->for($this->property)->for($this->standard)->create();

    $this->actingAs($other)->put(route('onboarding.rooms.update', $this->property->code), roomsPayload([roomRange($this->standard, '1', '2')]))
        ->assertNotFound();
    $this->actingAs($other)->delete(route('onboarding.rooms.destroy', [$other->property->code, $room]))
        ->assertNotFound();

    expect($room->refresh()->archived_at)->toBeNull();
});
