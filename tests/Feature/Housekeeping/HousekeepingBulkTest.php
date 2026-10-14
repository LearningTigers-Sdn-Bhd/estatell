<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\HousekeepingStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
});

function bulkProperty(User $user): Property
{
    BusinessDate::factory()->create(['property_id' => $user->property_id, 'date' => '2026-10-12']);

    return $user->property;
}

function bulkRoom(Property $property, string $number, HousekeepingStatus $status = HousekeepingStatus::Dirty): Room
{
    $room = Room::factory()->for($property)->create(['number' => $number]);
    $room->status->update(['status' => $status]);

    return $room;
}

/**
 * @param  list<Room>  $rooms
 * @param  array<string, mixed>  $payload
 */
function bulkPost(Property $property, array $rooms, string $action, array $payload = []): TestResponse
{
    return test()->post(route('housekeeping.bulk', $property->code), [
        'room_ids' => array_map(fn (Room $room): int => $room->id, $rooms),
        'action' => $action,
        ...$payload,
    ]);
}

test('mark clean moves Dirty rooms, counts a Clean room as done and skips the rest with the reason', function () {
    $owner = User::factory()->owner()->create();
    $property = bulkProperty($owner);
    $dirty = bulkRoom($property, '101');
    $alreadyClean = bulkRoom($property, '102', HousekeepingStatus::Clean);
    $inspected = bulkRoom($property, '103', HousekeepingStatus::Inspected);
    $blocked = bulkRoom($property, '104');
    RoomBlock::factory()->for($blocked)->create(['property_id' => $property->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);

    $this->actingAs($owner);

    bulkPost($property, [$dirty, $alreadyClean, $inspected, $blocked], 'mark_clean')
        ->assertRedirect(route('housekeeping.index', $property->code))
        ->assertInertiaFlash('toast', [
            'type' => 'info',
            'message' => '2 rooms updated. 2 skipped: Room 103 cannot move to that status. Room 104 is out of service.',
        ]);

    expect($dirty->status->fresh()->status)->toBe(HousekeepingStatus::Clean)
        ->and($inspected->status->fresh()->status)->toBe(HousekeepingStatus::Inspected)
        ->and($blocked->status->fresh()->status)->toBe(HousekeepingStatus::Dirty)
        ->and(Activity::query()->where('event', 'room_status.changed')->count())->toBe(1);
});

test('mark inspected moves Clean rooms and needs housekeeping.inspect', function () {
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $property = bulkProperty($housekeeper);
    $room = bulkRoom($property, '101', HousekeepingStatus::Clean);

    $this->actingAs($housekeeper);
    bulkPost($property, [$room], 'mark_inspected')->assertForbidden();

    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $property->id]);

    $this->actingAs($manager);
    bulkPost($property, [$room], 'mark_inspected')->assertRedirect();

    expect($room->status->fresh()->status)->toBe(HousekeepingStatus::Inspected);
});

test('assign, priority, remark and maintenance work on every room, including one out of service', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = bulkProperty($manager);
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $property->id]);
    $first = bulkRoom($property, '101');
    $second = bulkRoom($property, '102');
    $second->status->update(['remark' => 'Key at desk', 'priority_note' => 'VIP']);
    RoomBlock::factory()->for($second)->create(['property_id' => $property->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14']);
    $rooms = [$first, $second];

    $this->actingAs($manager);

    bulkPost($property, $rooms, 'assign', ['assigned_to' => $housekeeper->id])->assertSessionHasNoErrors();
    expect($first->status->fresh()->assigned_to)->toBe($housekeeper->id)
        ->and($second->status->fresh()->assigned_to)->toBe($housekeeper->id);

    bulkPost($property, $rooms, 'assign', ['assigned_to' => null])->assertSessionHasNoErrors();
    expect($first->status->fresh()->assigned_to)->toBeNull();

    bulkPost($property, $rooms, 'set_priority')->assertSessionHasNoErrors();
    expect($first->status->fresh()->is_priority)->toBeTrue()
        ->and($second->status->fresh()->priority_note)->toBe('VIP');

    bulkPost($property, $rooms, 'clear_priority')->assertSessionHasNoErrors();
    expect($second->status->fresh()->is_priority)->toBeFalse();

    bulkPost($property, $rooms, 'add_remark', ['remark' => 'Check the lamp'])->assertSessionHasNoErrors();
    expect($first->status->fresh()->remark)->toBe('Check the lamp')
        ->and($second->status->fresh()->remark)->toBe("Key at desk\nCheck the lamp");

    bulkPost($property, $rooms, 'needs_maintenance')->assertSessionHasNoErrors();
    expect($first->status->fresh()->needs_maintenance)->toBeTrue()
        ->and($second->status->fresh()->needs_maintenance)->toBeTrue();
});

test('an added remark is cut at 500 characters', function () {
    $owner = User::factory()->owner()->create();
    $property = bulkProperty($owner);
    $room = bulkRoom($property, '101');
    $room->status->update(['remark' => str_repeat('a', 480)]);

    $this->actingAs($owner);
    bulkPost($property, [$room], 'add_remark', ['remark' => str_repeat('b', 100)])->assertSessionHasNoErrors();

    expect(mb_strlen($room->status->fresh()->remark))->toBe(500);
});

test('running the same bulk action twice changes nothing the second time', function () {
    $owner = User::factory()->owner()->create();
    $property = bulkProperty($owner);
    $room = bulkRoom($property, '101');

    $this->actingAs($owner);
    bulkPost($property, [$room], 'needs_maintenance');
    bulkPost($property, [$room], 'mark_clean');
    bulkPost($property, [$room], 'needs_maintenance');
    bulkPost($property, [$room], 'mark_clean');

    expect(Activity::query()->where('event', 'room_status.maintenance_flagged')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'room_status.changed')->count())->toBe(1);
});

test('a room of another property, or an archived room, makes the whole request 404', function () {
    $owner = User::factory()->owner()->create();
    $property = bulkProperty($owner);
    $mine = bulkRoom($property, '101');
    $other = Room::factory()->create();
    $archived = Room::factory()->for($property)->archived()->create();

    $this->actingAs($owner);

    bulkPost($property, [$mine, $other], 'mark_clean')->assertNotFound();
    bulkPost($property, [$mine, $archived], 'mark_clean')->assertNotFound();

    expect($mine->status->fresh()->status)->toBe(HousekeepingStatus::Dirty);
});

test('the request needs rooms, a known action and a remark for add remark', function () {
    $owner = User::factory()->owner()->create();
    $property = bulkProperty($owner);
    $room = bulkRoom($property, '101');

    $this->actingAs($owner);

    $this->post(route('housekeeping.bulk', $property->code), ['room_ids' => [], 'action' => 'mark_clean'])->assertSessionHasErrors('room_ids');
    bulkPost($property, [$room], 'delete_everything')->assertSessionHasErrors('action');
    bulkPost($property, [$room], 'add_remark')->assertSessionHasErrors('remark');
});

test('staff without housekeeping.update cannot run a bulk action', function () {
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create();
    $property = bulkProperty($finance);
    $room = bulkRoom($property, '101');

    $this->actingAs($finance);
    bulkPost($property, [$room], 'mark_clean')->assertForbidden();
});
