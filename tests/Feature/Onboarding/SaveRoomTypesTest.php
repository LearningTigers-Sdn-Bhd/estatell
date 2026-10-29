<?php

use App\Actions\Onboarding\SaveOnboardingRoomTypes;
use App\Enums\SellUnit;
use App\Enums\StepState;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Onboarding\Checks\RoomTypesCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    $this->save = fn (array $rows) => $this->actingAs($this->owner)
        ->put(route('onboarding.room-types.update', $this->property->code), ['rows' => $rows]);
});

function roomTypeRow(string $name, array $overrides = []): array
{
    return ['id' => null, 'name' => $name, 'sell_unit' => 'room', 'max_adults' => 2, 'max_children' => 0, ...$overrides];
}

test('the check is done with any active room type and not started without one', function () {
    $check = new RoomTypesCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    RoomType::factory()->for($this->property)->archived()->create();
    expect($check->state($this->property))->toBe(StepState::NotStarted);

    RoomType::factory()->for($this->property)->bed()->create();
    expect($check->state($this->property))->toBe(StepState::Done);
});

test('new rows create room types sold by room in table order with guests included from max adults', function () {
    ($this->save)([
        roomTypeRow(' Standard Double ', ['max_adults' => 3, 'max_children' => 1]),
        roomTypeRow('Deluxe King'),
    ])->assertSessionHasNoErrors()->assertRedirect(route('onboarding.rooms.edit', $this->property->code));

    $types = $this->property->roomTypes()->ordered()->get();

    expect($types->pluck('name')->all())->toBe(['Standard Double', 'Deluxe King'])
        ->and($types->pluck('sell_unit')->unique()->all())->toBe([SellUnit::Room])
        ->and($types[0]->only(['max_adults', 'max_children', 'base_occupancy']))->toBe(['max_adults' => 3, 'max_children' => 1, 'base_occupancy' => 3]);
});

test('a row with an id updates, a missing room type is archived with its rooms, and the order follows the table', function () {
    $keep = RoomType::factory()->for($this->property)->create(['name' => 'Keep', 'position' => 1]);
    $drop = RoomType::factory()->for($this->property)->create(['name' => 'Drop', 'position' => 2]);
    $room = Room::factory()->for($this->property)->for($drop)->create(['number' => '101']);

    ($this->save)([
        roomTypeRow('Fresh'),
        roomTypeRow('Kept and renamed', ['id' => $keep->id, 'max_adults' => 4]),
    ])->assertSessionHasNoErrors();

    expect($keep->refresh()->name)->toBe('Kept and renamed')
        ->and($keep->base_occupancy)->toBe(4)
        ->and($drop->refresh()->archived_at)->not->toBeNull()
        ->and($room->refresh()->archived_at)->not->toBeNull()
        ->and($this->property->roomTypes()->active()->ordered()->pluck('name')->all())->toBe(['Fresh', 'Kept and renamed']);

    $replacement = Room::factory()->for($this->property)->for($keep)->create(['number' => '101']);
    expect($replacement->exists)->toBeTrue();
});

test('a new row may reuse the name of a removed room type', function () {
    $old = RoomType::factory()->for($this->property)->create(['name' => 'Garden Room']);

    ($this->save)([roomTypeRow('garden room')])->assertSessionHasNoErrors();

    expect($old->refresh()->archived_at)->not->toBeNull()
        ->and($this->property->roomTypes()->active()->pluck('name')->all())->toBe(['garden room']);
});

test('a new row sold by bed creates a room type sold by bed', function () {
    ($this->save)([
        roomTypeRow('Private Room'),
        roomTypeRow('6-Bed Mixed Dorm', ['sell_unit' => 'bed', 'max_adults' => 1]),
    ])->assertSessionHasNoErrors();

    expect($this->property->roomTypes()->ordered()->get()->map->only(['name', 'sell_unit', 'base_occupancy'])->all())->toBe([
        ['name' => 'Private Room', 'sell_unit' => SellUnit::Room, 'base_occupancy' => 2],
        ['name' => '6-Bed Mixed Dorm', 'sell_unit' => SellUnit::Bed, 'base_occupancy' => 1],
    ]);
});

test('room types sold by bed are rows like any other: updated, ordered, archived and named apart', function () {
    $dorm = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Dorm Bed', 'position' => 1]);
    $old = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Old Dorm', 'position' => 2]);
    $room = Room::factory()->for($this->property)->for($old)->create();
    $bed = Bed::factory()->for($room)->create();

    ($this->save)([roomTypeRow('dorm bed'), roomTypeRow('Dorm Bed', ['id' => $dorm->id, 'sell_unit' => 'bed'])])->assertSessionHasErrors('rows.0.name');

    ($this->save)([
        roomTypeRow('Private Room'),
        roomTypeRow('Mixed Dorm', ['id' => $dorm->id, 'sell_unit' => 'bed', 'max_adults' => 1]),
    ])->assertSessionHasNoErrors();

    expect($dorm->refresh()->only(['name', 'sell_unit', 'max_adults']))->toBe(['name' => 'Mixed Dorm', 'sell_unit' => SellUnit::Bed, 'max_adults' => 1])
        ->and($old->refresh()->archived_at)->not->toBeNull()
        ->and($room->refresh()->archived_at)->not->toBeNull()
        ->and($bed->refresh()->archived_at)->toBeNull()
        ->and($this->property->roomTypes()->active()->ordered()->pluck('name')->all())->toBe(['Private Room', 'Mixed Dorm']);
});

test('sold by changes on a saved room type without rooms and is fixed once it has any room', function () {
    $empty = RoomType::factory()->for($this->property)->create(['name' => 'Empty']);
    $withRoom = RoomType::factory()->for($this->property)->create(['name' => 'With room']);
    Room::factory()->for($this->property)->for($withRoom)->archived()->create();

    ($this->save)([
        roomTypeRow('Empty', ['id' => $empty->id]),
        roomTypeRow('With room', ['id' => $withRoom->id, 'sell_unit' => 'bed']),
    ])->assertSessionHasErrors(['rows.1.sell_unit' => 'Sold by cannot change while this room type has rooms.']);

    expect($withRoom->refresh()->sell_unit)->toBe(SellUnit::Room);

    ($this->save)([
        roomTypeRow('Empty', ['id' => $empty->id, 'sell_unit' => 'bed']),
        roomTypeRow('With room', ['id' => $withRoom->id]),
    ])->assertSessionHasNoErrors();

    expect($empty->refresh()->sell_unit)->toBe(SellUnit::Bed);
});

test('each row needs a valid sell unit', function () {
    ($this->save)([roomTypeRow('Standard', ['sell_unit' => null]), roomTypeRow('Dorm', ['sell_unit' => 'tent'])])
        ->assertSessionHasErrors(['rows.0.sell_unit', 'rows.1.sell_unit']);

    expect($this->property->roomTypes()->count())->toBe(0);
});

test('each row shows its own error and nothing is saved', function () {
    $saved = RoomType::factory()->for($this->property)->create(['name' => 'Saved']);

    ($this->save)([
        roomTypeRow('Saved', ['id' => $saved->id]),
        roomTypeRow(''),
        roomTypeRow('Villa', ['max_adults' => 0]),
        roomTypeRow('Suite', ['max_children' => 51]),
        roomTypeRow('villa'),
    ])->assertSessionHasErrors(['rows.1.name', 'rows.2.max_adults', 'rows.3.max_children', 'rows.2.name', 'rows.4.name']);

    expect($this->property->roomTypes()->count())->toBe(1);
});

test('two kept rows cannot swap names in one save', function () {
    $first = RoomType::factory()->for($this->property)->create(['name' => 'A']);
    $second = RoomType::factory()->for($this->property)->create(['name' => 'B']);

    ($this->save)([
        roomTypeRow('B', ['id' => $first->id]),
        roomTypeRow('A', ['id' => $second->id]),
    ])->assertSessionHasErrors(['rows.0.name', 'rows.1.name']);

    expect($first->refresh()->name)->toBe('A');
});

test('at least one row is needed and the table has a size limit', function () {
    ($this->save)([])->assertSessionHasErrors('rows');
    ($this->save)(array_map(fn (int $number) => roomTypeRow("Type {$number}"), range(1, 51)))->assertSessionHasErrors('rows');
});

test('a row id must be an active room type in this property', function () {
    $other = RoomType::factory()->create();
    $archived = RoomType::factory()->for($this->property)->archived()->create();

    foreach ([$other->id, $archived->id] as $id) {
        ($this->save)([roomTypeRow('Standard', ['id' => $id])])->assertSessionHasErrors('rows.0.id');
    }

    ($this->save)([roomTypeRow('One', ['id' => $archived->id]), roomTypeRow('Two', ['id' => $archived->id])])->assertSessionHasErrors();

    expect($other->refresh()->name)->not->toBe('Standard');
});

test('two new rows are not treated as the same id', function () {
    ($this->save)([roomTypeRow('One'), roomTypeRow('Two')])->assertSessionHasNoErrors();

    expect($this->property->roomTypes()->count())->toBe(2);
});

test('saving twice with the same rows changes nothing the second time', function () {
    ($this->save)([roomTypeRow('Standard')])->assertSessionHasNoErrors();
    $type = $this->property->roomTypes()->sole();

    ($this->save)([roomTypeRow('Standard', ['id' => $type->id])])->assertSessionHasNoErrors();

    expect($this->property->roomTypes()->count())->toBe(1)
        ->and($type->refresh()->archived_at)->toBeNull();
});

test('a save is rejected while basics is not done', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($owner)
        ->put(route('onboarding.room-types.update', $owner->property->code), ['rows' => [roomTypeRow('Standard')]])
        ->assertSessionHasErrors('onboarding');

    expect($owner->property->roomTypes()->count())->toBe(0);
});

test('the page sends every room type with whether it has rooms and opens as a preview while locked', function () {
    $room = RoomType::factory()->for($this->property)->create(['name' => 'Standard', 'position' => 1]);
    $dorm = RoomType::factory()->for($this->property)->bed()->create(['name' => 'Dorm', 'position' => 2]);
    Room::factory()->for($this->property)->for($dorm)->archived()->create();

    $this->actingAs($this->owner)
        ->get(route('onboarding.room-types.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/room-types')
            ->has('roomTypes', 2)
            ->where('roomTypes.0.id', $room->id)
            ->where('roomTypes.0.hasRooms', false)
            ->where('roomTypes.1.sellUnit', 'bed')
            ->where('roomTypes.1.hasRooms', true)
            ->where('sellUnits', SellUnit::options())
            ->where('locked', false)
            ->where('blockedBy', null));

    $fresh = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($fresh)
        ->get(route('onboarding.room-types.edit', $fresh->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locked', true)
            ->where('blockedBy.label', 'Basics')
            ->where('blockedBy.url', route('onboarding.basics.edit', $fresh->property->code, absolute: false)));
});

test('the room types step is listed in the Rooms phase', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.room-types.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.phases.1.value', 'rooms')
            ->where('onboarding.phases.1.steps.0.value', 'room_types')
            ->where('onboarding.phases.1.steps.0.url', route('onboarding.room-types.edit', $this->property->code, absolute: false)));
});

test('another property\'s staff cannot open or save the page', function () {
    $other = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($other)->get(route('onboarding.room-types.edit', $this->property->code))->assertNotFound();
    $this->actingAs($other)->put(route('onboarding.room-types.update', $this->property->code), ['rows' => [roomTypeRow('Standard')]])->assertNotFound();
});

test('the action alone creates and archives room types sold by bed', function () {
    $dorm = RoomType::factory()->for($this->property)->bed()->create();

    app(SaveOnboardingRoomTypes::class)($this->property, $this->owner, [['id' => null, 'name' => 'New Dorm', 'sell_unit' => SellUnit::Bed, 'max_adults' => 1, 'max_children' => 0]]);

    expect($dorm->refresh()->archived_at)->not->toBeNull()
        ->and($this->property->roomTypes()->active()->sole()->only(['name', 'sell_unit']))->toBe(['name' => 'New Dorm', 'sell_unit' => SellUnit::Bed]);
});
