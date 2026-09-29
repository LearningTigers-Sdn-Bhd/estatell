<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingStatus;
use App\Enums\RoomBlockReason;
use App\Models\Bed;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function blockPayload(array $overrides = []): array
{
    return [
        'reason' => 'maintenance',
        'starts_on' => '2026-10-12',
        'ends_on' => '2026-10-14',
        'notes' => 'Aircon repair',
        ...$overrides,
    ];
}

function blockedDorm(Property $property): Room
{
    return Room::factory()->for($property)->create([
        'room_type_id' => RoomType::factory()->for($property)->bed()->create()->id,
    ]);
}

function blockOn(Room $room, array $attributes = []): RoomBlock
{
    return RoomBlock::factory()->for($room)->create(['property_id' => $room->property_id, ...$attributes]);
}

test('the page lists current and upcoming blocks, recent past ones and the rooms that can be blocked', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create(['number' => '203']);
    $dorm = blockedDorm($property);
    $bed = Bed::factory()->for($dorm)->create(['label' => 'A1']);
    Room::factory()->for($property)->archived()->create();
    blockOn($room, ['starts_on' => '2026-10-15', 'ends_on' => '2026-10-16']);
    blockOn($room, ['starts_on' => '2026-10-09', 'ends_on' => '2026-10-11']);
    blockOn($room, ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
    blockOn(Room::factory()->create());

    $this->actingAs($owner)
        ->get(route('front-desk.room-blocks.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('front-desk/room-blocks/index')
            ->has('blocks', 3)
            ->where('blocks.0.startsOn', '2026-10-09')
            ->where('blocks.0.roomNumber', '203')
            ->where('blocks.1.startsOn', '2026-10-15')
            ->where('blocks.2.endsOn', '2026-10-02')
            ->has('rooms', 2)
            ->where('rooms.1.sellsBeds', true)
            ->where('rooms.1.beds.0.id', $bed->id)
            ->where('today', '2026-10-10')
            ->where('maxNights', 365));
});

test('front desk staff block a room', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $property = $frontDesk->property;
    $room = Room::factory()->for($property)->create(['number' => '203']);

    $this->actingAs($frontDesk)
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(), 'room_id' => $room->id, 'notes' => '  Aircon repair '])
        ->assertRedirect(route('front-desk.room-blocks.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room 203 blocked.']);

    $block = RoomBlock::query()->sole();

    expect($block->property_id)->toBe($property->id)
        ->and($block->room_id)->toBe($room->id)
        ->and($block->bed_id)->toBeNull()
        ->and($block->reason)->toBe(RoomBlockReason::Maintenance)
        ->and($block->starts_on->toDateString())->toBe('2026-10-12')
        ->and($block->notes)->toBe('Aircon repair')
        ->and($block->created_by)->toBe($frontDesk->id)
        ->and(Activity::query()->where('event', 'room_block.created')->count())->toBe(1);
});

test('a bed of a dorm room can be blocked', function () {
    $owner = User::factory()->owner()->create();
    $dorm = blockedDorm($owner->property);
    $bed = Bed::factory()->for($dorm)->create();

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [...blockPayload(), 'room_id' => $dorm->id, 'bed_id' => $bed->id])
        ->assertSessionHasNoErrors();

    expect(RoomBlock::query()->sole()->bed_id)->toBe($bed->id);
});

test('block input is validated', function (Closure $payload, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(['room_id' => $room->id]), ...$payload($property, $room)])
        ->assertSessionHasErrors($field);

    expect(RoomBlock::query()->count())->toBe(0);
})->with([
    'room is required' => [fn () => ['room_id' => null], 'room_id'],
    'room must be active' => [fn (Property $property) => ['room_id' => Room::factory()->for($property)->archived()->create()->id], 'room_id'],
    'room of another property' => [fn () => ['room_id' => Room::factory()->create()->id], 'room_id'],
    'bed must be in the room' => [fn (Property $property) => ['bed_id' => Bed::factory()->for(blockedDorm($property))->create()->id], 'bed_id'],
    'bed must be active' => [fn (Property $property, Room $room) => ['bed_id' => Bed::factory()->for($room)->archived()->create()->id], 'bed_id'],
    'reason must be known' => [fn () => ['reason' => 'party'], 'reason'],
    'first night not in the past' => [fn () => ['starts_on' => '2026-10-09'], 'starts_on'],
    'last night not before the first' => [fn () => ['ends_on' => '2026-10-11'], 'ends_on'],
    'at most 365 nights' => [fn () => ['ends_on' => '2027-10-12'], 'ends_on'],
    'dates use Y-m-d' => [fn () => ['starts_on' => '12/10/2026'], 'starts_on'],
    'notes required for Other' => [fn () => ['reason' => 'other', 'notes' => ' '], 'notes'],
    'notes at most 500 characters' => [fn () => ['notes' => str_repeat('a', 501)], 'notes'],
]);

test('today is the property\'s local date', function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(17, 0));
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['time_zone' => 'Asia/Kuala_Lumpur']);
    $room = Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-11']), 'room_id' => $room->id])
        ->assertSessionHasErrors('starts_on');
    $this->get(route('front-desk.room-blocks.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('today', '2026-10-11'));
});

test('overlapping blocks are refused', function (?string $existingBed, ?string $newBed, bool $refused) {
    $owner = User::factory()->owner()->create();
    $dorm = blockedDorm($owner->property);
    $beds = ['A1' => Bed::factory()->for($dorm)->create(), 'A2' => Bed::factory()->for($dorm)->create()];
    blockOn($dorm, ['bed_id' => $existingBed ? $beds[$existingBed]->id : null, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-20']);
    blockOn($dorm, ['starts_on' => '2026-10-11', 'ends_on' => '2026-10-13', 'completed_at' => now()]);

    $response = $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [
            ...blockPayload(['starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']),
            'room_id' => $dorm->id,
            'bed_id' => $newBed ? $beds[$newBed]->id : null,
        ]);

    expect(RoomBlock::query()->count())->toBe($refused ? 2 : 3);

    if ($refused) {
        $response->assertInertiaFlash('toast.type', 'error');
    }
})->with([
    'room over room' => [null, null, true],
    'bed over room' => [null, 'A1', true],
    'room over bed' => ['A1', null, true],
    'same bed' => ['A1', 'A1', true],
    'different beds' => ['A1', 'A2', false],
]);

test('back-to-back blocks do not overlap', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create();
    blockOn($room, ['starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [...blockPayload(['starts_on' => '2026-10-15', 'ends_on' => '2026-10-16']), 'room_id' => $room->id])
        ->assertInertiaFlash('toast.type', 'success');

    expect(RoomBlock::query()->count())->toBe(2);
});

test('staff edit the reason, nights and notes of an upcoming block', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create();
    $block = blockOn($room);
    $other = blockOn($room, ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-21']);
    $url = route('front-desk.room-blocks.update', [$owner->property->code, $block]);

    $this->actingAs($owner)
        ->put($url, blockPayload(['reason' => 'owner_use', 'starts_on' => '2026-10-13', 'ends_on' => '2026-10-19', 'notes' => '']))
        ->assertInertiaFlash('toast.type', 'success');

    expect($block->refresh()->reason)->toBe(RoomBlockReason::OwnerUse)
        ->and($block->starts_on->toDateString())->toBe('2026-10-13')
        ->and($block->ends_on->toDateString())->toBe('2026-10-19')
        ->and($block->notes)->toBeNull();

    $this->put($url, blockPayload(['ends_on' => '2026-10-20']))->assertInertiaFlash('toast.type', 'error');

    expect($block->refresh()->ends_on->toDateString())->toBe('2026-10-19')
        ->and($other->refresh()->starts_on->toDateString())->toBe('2026-10-20');
});

test('a started block keeps its first night and can be extended', function () {
    $owner = User::factory()->owner()->create();
    $block = blockOn(Room::factory()->for($owner->property)->create(), ['starts_on' => '2026-10-08', 'ends_on' => '2026-10-11']);
    $url = route('front-desk.room-blocks.update', [$owner->property->code, $block]);

    $this->actingAs($owner)
        ->put($url, blockPayload(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-15']))
        ->assertSessionHasErrors('starts_on');
    $this->put($url, blockPayload(['starts_on' => '2026-10-08', 'ends_on' => '2026-10-15']))
        ->assertSessionHasNoErrors();

    expect($block->refresh()->ends_on->toDateString())->toBe('2026-10-15');
});

test('an ended block cannot be edited', function () {
    $owner = User::factory()->owner()->create();
    $block = blockOn(Room::factory()->for($owner->property)->create(), ['completed_at' => now()]);

    $this->actingAs($owner)
        ->put(route('front-desk.room-blocks.update', [$owner->property->code, $block]), blockPayload(['reason' => 'other']))
        ->assertInertiaFlash('toast.type', 'error');

    expect($block->refresh()->reason)->toBe(RoomBlockReason::Maintenance);
});

test('ending a running room block sets the room status staff choose', function (string $status) {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create(['number' => '203']);
    $block = blockOn($room, ['starts_on' => '2026-10-08', 'ends_on' => '2026-10-12']);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.end', [$owner->property->code, $block]), ['room_status' => $status])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room 203 is back in service.']);

    expect($block->refresh()->completed_at)->not->toBeNull()
        ->and($block->ends_on->toDateString())->toBe('2026-10-12')
        ->and($room->status->status->value)->toBe($status)
        ->and($room->status->last_changed_by)->toBe($owner->id)
        ->and(Activity::query()->where('event', 'room_block.ended')->count())->toBe(1);
})->with(['clean', 'dirty']);

test('ending a room block needs a clean or dirty room status', function () {
    $owner = User::factory()->owner()->create();
    $block = blockOn(Room::factory()->for($owner->property)->create(), ['starts_on' => '2026-10-10']);
    $url = route('front-desk.room-blocks.end', [$owner->property->code, $block]);

    $this->actingAs($owner)->post($url)->assertSessionHasErrors('room_status');
    $this->post($url, ['room_status' => 'inspected'])->assertSessionHasErrors('room_status');

    expect($block->refresh()->completed_at)->toBeNull();
});

test('ending a bed block leaves the room status alone', function () {
    $owner = User::factory()->owner()->create();
    $dorm = blockedDorm($owner->property);
    $dorm->status->update(['status' => HousekeepingStatus::Inspected]);
    $block = blockOn($dorm, ['bed_id' => Bed::factory()->for($dorm)->create()->id, 'starts_on' => '2026-10-10']);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.end', [$owner->property->code, $block]))
        ->assertInertiaFlash('toast.type', 'success');

    expect($block->refresh()->completed_at)->not->toBeNull()
        ->and($dorm->status->refresh()->status)->toBe(HousekeepingStatus::Inspected);
});

test('only a block running today can end', function (array $attributes) {
    $owner = User::factory()->owner()->create();
    $block = blockOn(Room::factory()->for($owner->property)->create(), $attributes);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.end', [$owner->property->code, $block]), ['room_status' => 'clean'])
        ->assertInertiaFlash('toast.type', 'error');

    expect(Activity::query()->where('event', 'room_block.ended')->count())->toBe(0);
})->with([
    'upcoming' => [['starts_on' => '2026-10-11', 'ends_on' => '2026-10-12']],
    'already over' => [['starts_on' => '2026-10-05', 'ends_on' => '2026-10-09']],
    'already ended' => [['starts_on' => '2026-10-09', 'ends_on' => '2026-10-12', 'completed_at' => '2026-10-09 10:00:00']],
]);

test('an upcoming block can be cancelled and a started one cannot', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create(['number' => '203']);
    $upcoming = blockOn($room, ['starts_on' => '2026-10-11', 'ends_on' => '2026-10-12']);
    $started = blockOn($room, ['starts_on' => '2026-10-10', 'ends_on' => '2026-10-10']);

    $this->actingAs($owner)
        ->delete(route('front-desk.room-blocks.destroy', [$owner->property->code, $upcoming]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Block for Room 203 cancelled.']);
    $this->delete(route('front-desk.room-blocks.destroy', [$owner->property->code, $started]))
        ->assertInertiaFlash('toast.type', 'error');

    expect(RoomBlock::query()->pluck('id')->all())->toBe([$started->id])
        ->and(Activity::query()->where('event', 'room_block.cancelled')->sole()->properties['starts_on'])->toBe('2026-10-11');
});

test('archiving a room or bed ends its current and upcoming blocks', function () {
    $owner = User::factory()->owner()->create();
    $code = $owner->property->code;
    $room = Room::factory()->for($owner->property)->create();
    $past = blockOn($room, ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
    $running = blockOn($room, ['starts_on' => '2026-10-09', 'ends_on' => '2026-10-11']);
    $upcoming = blockOn($room, ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-21']);
    $dorm = blockedDorm($owner->property);
    $bed = Bed::factory()->for($dorm)->create();
    $bedBlock = blockOn($dorm, ['bed_id' => $bed->id]);

    $this->actingAs($owner)->post(route('settings.rooms.archive', [$code, $room]));
    $this->post(route('settings.rooms.beds.archive', [$code, $dorm, $bed]));

    expect($past->refresh()->completed_at)->toBeNull()
        ->and($running->refresh()->completed_at)->not->toBeNull()
        ->and($upcoming->refresh()->completed_at)->not->toBeNull()
        ->and($bedBlock->refresh()->completed_at)->not->toBeNull();
});

test('the database rejects invalid blocks', function (array $attributes) {
    $room = Room::factory()->create();

    expect(fn () => blockOn($room, $attributes))->toThrow(QueryException::class);
})->with([
    'last night before first' => [['starts_on' => '2026-10-12', 'ends_on' => '2026-10-11']],
    'more than 365 nights' => [['starts_on' => '2026-10-12', 'ends_on' => '2027-10-12']],
    'other without notes' => [['reason' => 'other', 'notes' => null]],
]);

test('blocking needs rooms.block', function () {
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $code = $housekeeping->property->code;
    $block = blockOn(Room::factory()->for($housekeeping->property)->create());

    $this->actingAs($housekeeping)->get(route('front-desk.room-blocks.index', $code))->assertForbidden();
    $this->post(route('front-desk.room-blocks.store', $code), [])->assertForbidden();
    $this->put(route('front-desk.room-blocks.update', [$code, $block]), [])->assertForbidden();
    $this->post(route('front-desk.room-blocks.end', [$code, $block]))->assertForbidden();
    $this->delete(route('front-desk.room-blocks.destroy', [$code, $block]))->assertForbidden();
});

test('another property\'s blocks are not reachable', function () {
    $owner = User::factory()->owner()->create();
    $code = $owner->property->code;
    $block = blockOn(Room::factory()->create());

    $this->actingAs($owner)->put(route('front-desk.room-blocks.update', [$code, $block]), blockPayload())->assertNotFound();
    $this->post(route('front-desk.room-blocks.end', [$code, $block]), ['room_status' => 'clean'])->assertNotFound();
    $this->delete(route('front-desk.room-blocks.destroy', [$code, $block]))->assertNotFound();
    $this->get(route('front-desk.room-blocks.index', $block->property->code))->assertNotFound();

    expect($block->refresh()->completed_at)->toBeNull();
});

test('the front desk sidebar shows room blocks only with rooms.block', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $housekeeping = User::factory()->for($frontDesk->property)->withRole(DefaultRoleEnum::Housekeeping)->create();
    $code = $frontDesk->property->code;

    $this->actingAs($frontDesk)
        ->get(route('front-desk.room-blocks.index', $code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'front-desk')
            ->where('navigation.contexts.0.groups.1.items.0.href', "/p/{$code}/front-desk/room-blocks")
            ->where('navigation.contexts.0.groups.1.items.0.active', true));

    $this->actingAs($housekeeping)
        ->get(route('dashboard', $code))
        ->assertInertia(fn (Assert $page) => $page->has('navigation.contexts.0.groups', 1));
});
