<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingStatus;
use App\Enums\RoomBlockReason;
use App\Models\Bed;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\UnitNight;
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
        ->from(route('front-desk.room-blocks.index', $property->code))
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(), 'room_ids' => [$room->id], 'notes' => '  Aircon repair '])
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
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [...blockPayload(), 'room_ids' => [$dorm->id], 'bed_id' => $bed->id])
        ->assertSessionHasNoErrors();

    expect(RoomBlock::query()->sole()->bed_id)->toBe($bed->id);
});

test('several rooms are blocked at once and a refused room is skipped', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $rooms = collect(['106', '107', '108'])->map(fn (string $number): Room => Room::factory()->for($property)->create(['number' => $number]));
    $night = UnitNight::factory()->create(['room_id' => $rooms[2]->id, 'date' => '2026-10-13']);
    $reference = $night->reservationUnit->reservation->reference;

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(), 'room_ids' => $rooms->pluck('id')->all()])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => "2 rooms blocked. 1 skipped: Room 108 has a stay on 13 Oct 2026 (booking {$reference}). Move the stay first."]);

    expect(RoomBlock::query()->orderBy('room_id')->pluck('room_id')->all())->toBe([$rooms[0]->id, $rooms[1]->id])
        ->and(Activity::query()->where('event', 'room_block.created')->count())->toBe(2);
});

test('blocking several rooms that are all refused is an error', function () {
    $owner = User::factory()->owner()->create();
    $rooms = Room::factory()->for($owner->property)->count(2)->create();
    $rooms->each(fn (Room $room) => blockOn($room, ['starts_on' => '2026-10-12', 'ends_on' => '2026-10-20']));

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [...blockPayload(), 'room_ids' => $rooms->pluck('id')->all()])
        ->assertInertiaFlash('toast.type', 'error');

    expect(RoomBlock::query()->count())->toBe(2);
});

test('ticked blocks end together and a block that has not started is skipped', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create(['number' => '106']);
    $dorm = blockedDorm($property);
    $dorm->update(['number' => '12']);
    $bed = Bed::factory()->for($dorm)->create(['label' => 'A1']);
    $running = blockOn($room, ['starts_on' => '2026-10-09', 'ends_on' => '2026-10-20']);
    $bedBlock = blockOn($dorm, ['bed_id' => $bed->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
    $upcoming = blockOn(Room::factory()->for($property)->create(['number' => '107']), ['starts_on' => '2026-10-15', 'ends_on' => '2026-10-16']);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.bulk-end', $property->code), ['block_ids' => [$running->id, $bedBlock->id, $upcoming->id], 'room_status' => 'clean'])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '2 blocks ended. 1 skipped: Block for Room 107 is not running today.']);

    expect($running->refresh()->completed_at)->not->toBeNull()
        ->and($bedBlock->refresh()->completed_at)->not->toBeNull()
        ->and($upcoming->refresh()->completed_at)->toBeNull()
        ->and($room->status->refresh()->status)->toBe(HousekeepingStatus::Clean)
        ->and(Activity::query()->where('event', 'room_block.ended')->count())->toBe(2);
});

test('bulk end asks the room status only when a whole room is ticked', function () {
    $owner = User::factory()->owner()->create();
    $dorm = blockedDorm($owner->property);
    $bedBlock = blockOn($dorm, ['bed_id' => Bed::factory()->for($dorm)->create()->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
    $roomBlock = blockOn(Room::factory()->for($owner->property)->create(), ['starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
    $url = route('front-desk.room-blocks.bulk-end', $owner->property->code);

    $this->actingAs($owner)->post($url, ['block_ids' => [$bedBlock->id, $roomBlock->id]])->assertSessionHasErrors('room_status');
    $this->post($url, ['block_ids' => [$bedBlock->id]])->assertSessionHasNoErrors();

    expect($bedBlock->refresh()->completed_at)->not->toBeNull()
        ->and($roomBlock->refresh()->completed_at)->toBeNull();
});

test('ticked blocks are cancelled together and a started block is skipped', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $upcoming = blockOn(Room::factory()->for($property)->create(), ['starts_on' => '2026-10-15', 'ends_on' => '2026-10-16']);
    $later = blockOn(Room::factory()->for($property)->create(), ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    $started = blockOn(Room::factory()->for($property)->create(['number' => '203']), ['starts_on' => '2026-10-09', 'ends_on' => '2026-10-20']);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.bulk-cancel', $property->code), ['block_ids' => [$upcoming->id, $later->id, $started->id]])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => '2 blocks cancelled. 1 skipped: Block for Room 203 has started. End it instead.']);

    expect(RoomBlock::query()->pluck('id')->all())->toBe([$started->id])
        ->and(Activity::query()->where('event', 'room_block.cancelled')->count())->toBe(2);
});

test('bulk actions only take blocks of this property', function (string $route) {
    $owner = User::factory()->owner()->create();
    $other = blockOn(Room::factory()->create(), ['starts_on' => '2026-10-15', 'ends_on' => '2026-10-16']);

    $this->actingAs($owner)
        ->post(route($route, $owner->property->code), ['block_ids' => [$other->id], 'room_status' => 'dirty'])
        ->assertSessionHasErrors('block_ids.0');

    expect($other->refresh()->exists)->toBeTrue();
})->with(['front-desk.room-blocks.bulk-end', 'front-desk.room-blocks.bulk-cancel']);

test('block input is validated', function (Closure $payload, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $room = Room::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(['room_ids' => [$room->id]]), ...$payload($property, $room)])
        ->assertSessionHasErrors($field);

    expect(RoomBlock::query()->count())->toBe(0);
})->with([
    'room is required' => [fn () => ['room_ids' => []], 'room_ids'],
    'room must be active' => [fn (Property $property) => ['room_ids' => [Room::factory()->for($property)->archived()->create()->id]], 'room_ids.0'],
    'room of another property' => [fn () => ['room_ids' => [Room::factory()->create()->id]], 'room_ids.0'],
    'rooms are distinct' => [fn (Property $property, Room $room) => ['room_ids' => [$room->id, $room->id]], 'room_ids.0'],
    'a bed needs one room' => [fn (Property $property) => ['room_ids' => [($dorm = blockedDorm($property))->id, Room::factory()->for($property)->create()->id], 'bed_id' => Bed::factory()->for($dorm)->create()->id], 'bed_id'],
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
        ->post(route('front-desk.room-blocks.store', $property->code), [...blockPayload(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-11']), 'room_ids' => [$room->id]])
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
            'room_ids' => [$dorm->id],
            'bed_id' => $newBed ? $beds[$newBed]->id : null,
        ]);

    expect(RoomBlock::query()->count())->toBe($refused ? 2 : 3);

    if ($refused) {
        $unit = $newBed ? "Room {$dorm->number}, bed {$beds[$newBed]->label}" : "Room {$dorm->number}";
        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => "{$unit} is already blocked from 14 Oct 2026 to 20 Oct 2026. To change those nights, edit that block."]);
    }
})->with([
    'room over room' => [null, null, true],
    'bed over room' => [null, 'A1', true],
    'room over bed' => ['A1', null, true],
    'same bed' => ['A1', 'A1', true],
    'different beds' => ['A1', 'A2', false],
]);

test('a block over a night with a stay is refused', function (string $stayOn, ?string $blockBed, bool $refused, string $date = '2026-10-13', bool $cancelled = false) {
    $owner = User::factory()->owner()->create();
    $dorm = blockedDorm($owner->property);
    $dorm->update(['number' => '12']);
    $beds = ['A1' => Bed::factory()->for($dorm)->create(['label' => 'A1']), 'A2' => Bed::factory()->for($dorm)->create(['label' => 'A2'])];
    $stayUnit = ($cancelled ? ReservationUnit::factory()->cancelled() : ReservationUnit::factory())->create();
    UnitNight::factory()->create([
        'reservation_unit_id' => $stayUnit->id,
        'date' => $date,
        ...($stayOn === 'room' ? ['room_id' => $dorm->id] : ['bed_id' => $beds[$stayOn]->id]),
    ]);

    $response = $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [
            ...blockPayload(),
            'room_ids' => [$dorm->id],
            'bed_id' => $blockBed ? $beds[$blockBed]->id : null,
        ]);

    expect(RoomBlock::query()->count())->toBe($refused ? 0 : 1);

    if ($refused) {
        $unit = $blockBed ? "Room 12, bed {$blockBed}" : 'Room 12';
        $reference = $stayUnit->reservation->reference;
        $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => "{$unit} has a stay on 13 Oct 2026 (booking {$reference}). Move the stay first."]);
        expect(Activity::query()->where('event', 'room_block.created')->count())->toBe(0);
    }
})->with([
    'room over its room' => ['room', null, true],
    'room over a bed' => ['A1', null, true],
    'bed over the same bed' => ['A1', 'A1', true],
    'bed over the whole room' => ['room', 'A1', true],
    'bed over another bed' => ['A1', 'A2', false],
    'night after the last night' => ['room', null, false, '2026-10-15'],
    'cancelled stay' => ['room', null, false, '2026-10-13', true],
]);

test('editing a block over a night with a stay is refused', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create(['number' => '203']);
    $block = blockOn($room, ['starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);
    $night = UnitNight::factory()->create(['room_id' => $room->id, 'date' => '2026-10-16']);
    $reference = $night->reservationUnit->reservation->reference;

    $this->actingAs($owner)
        ->put(route('front-desk.room-blocks.update', [$owner->property->code, $block]), blockPayload(['ends_on' => '2026-10-17']))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => "Room 203 has a stay on 16 Oct 2026 (booking {$reference}). Move the stay first."]);

    expect($block->refresh()->ends_on->toDateString())->toBe('2026-10-14')
        ->and(Activity::query()->where('event', 'room_block.updated')->count())->toBe(0);
});

test('the tab link opens the past tab and anything else opens current', function (?string $query, string $tab) {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('front-desk.room-blocks.index', [$owner->property->code, ...($query === null ? [] : ['tab' => $query])]))
        ->assertInertia(fn (Assert $page) => $page->where('tab', $tab));
})->with([
    'no tab' => [null, 'current'],
    'past' => ['past', 'past'],
    'unknown' => ['other', 'current'],
]);

test('editing a block over another block names that block', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create(['number' => '2017']);
    $block = blockOn($room, ['starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);
    blockOn($room, ['starts_on' => '2026-10-20', 'ends_on' => '2026-11-19']);

    $this->actingAs($owner)
        ->put(route('front-desk.room-blocks.update', [$owner->property->code, $block]), blockPayload(['ends_on' => '2026-12-31']))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room 2017 is already blocked from 20 Oct 2026 to 19 Nov 2026. To change those nights, edit that block.']);

    expect($block->refresh()->ends_on->toDateString())->toBe('2026-10-14');
});

test('a block made from the Timeline returns to the Timeline', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create();
    $timeline = route('front-desk.timeline', $owner->property->code);

    $this->actingAs($owner)
        ->from($timeline)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [...blockPayload(), 'room_ids' => [$room->id]])
        ->assertRedirect($timeline);
});

test('back-to-back blocks do not overlap', function () {
    $owner = User::factory()->owner()->create();
    $room = Room::factory()->for($owner->property)->create();
    blockOn($room, ['starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);

    $this->actingAs($owner)
        ->post(route('front-desk.room-blocks.store', $owner->property->code), [...blockPayload(['starts_on' => '2026-10-15', 'ends_on' => '2026-10-16']), 'room_ids' => [$room->id]])
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
    $this->post(route('front-desk.room-blocks.bulk-end', $code), ['block_ids' => [$block->id]])->assertForbidden();
    $this->post(route('front-desk.room-blocks.bulk-cancel', $code), ['block_ids' => [$block->id]])->assertForbidden();
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
        ->assertInertia(fn (Assert $page) => $page
            ->has('navigation.contexts.0.groups', 3)
            ->where('navigation.contexts.0.groups.1.label', 'Rooms')
            ->where('navigation.contexts.0.groups.1.items.0.title', 'Housekeeping')
            ->where('navigation.contexts.0.groups.2.label', 'Operations')
            ->where('navigation.contexts.0.groups.2.items.0.title', 'Rate calendar'));
});
