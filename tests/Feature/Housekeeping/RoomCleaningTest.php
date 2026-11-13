<?php

use App\Enums\HousekeepingRefusal;
use App\Enums\HousekeepingStatus;
use App\Enums\PermissionEnum;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCleaning;
use App\Models\User;
use App\Services\Housekeeping\RoomCleanings;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property in Kuala Lumpur, a Dirty room, a housekeeper and a front office user who may correct times.
 *
 * @return array{property: Property, room: Room, housekeeper: User, frontOffice: User}
 */
function cleaningSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create(['time_zone' => 'Asia/Kuala_Lumpur']);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    $room = Room::factory()->create(['property_id' => $property->id]);
    $room->status->forceFill(['status' => HousekeepingStatus::Dirty])->save();

    return [
        'property' => $property,
        'room' => $room,
        'housekeeper' => User::factory()->for($property)->withPermissions([PermissionEnum::HousekeepingView, PermissionEnum::HousekeepingUpdate])->create(['name' => 'Ana']),
        'frontOffice' => User::factory()->for($property)->withPermissions([PermissionEnum::HousekeepingView, PermissionEnum::HousekeepingUpdate, PermissionEnum::HousekeepingEditTimes])->create(['name' => 'Desk']),
    ];
}

function cleaningUrl(array $setup, string $path): string
{
    return "/p/{$setup['property']->code}/housekeeping/rooms/{$setup['room']->id}/{$path}";
}

test('Start records the housekeeper and the time, and Done ends it and makes a Dirty room Clean', function () {
    $setup = cleaningSetup();

    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/start'))->assertRedirect();

    $cleaning = RoomCleaning::query()->sole();

    expect($cleaning->housekeeper_id)->toBe($setup['housekeeper']->id)
        ->and($cleaning->property_id)->toBe($setup['property']->id)
        ->and($cleaning->date->toDateString())->toBe('2026-10-14')
        ->and($cleaning->started_at->diffInSeconds(now(), true))->toBeLessThan(1.0)
        ->and($cleaning->finished_at)->toBeNull()
        ->and($setup['room']->status->fresh()->status)->toBe(HousekeepingStatus::Dirty);

    $this->travel(35)->minutes();
    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/finish'))->assertRedirect();

    expect($cleaning->fresh()->finished_at->diffInSeconds(now(), true))->toBeLessThan(1.0)
        ->and($setup['room']->status->fresh()->status)->toBe(HousekeepingStatus::Clean)
        ->and(Activity::query()->where('event', 'room_cleaning.finished')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'room_status.changed')->count())->toBe(1);
});

test('Done leaves a room that is already Clean or Inspected as it is', function (HousekeepingStatus $status) {
    $setup = cleaningSetup();
    $setup['room']->status->forceFill(['status' => $status])->save();

    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/start'));
    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/finish'));

    expect($setup['room']->status->fresh()->status)->toBe($status);
})->with([HousekeepingStatus::Clean, HousekeepingStatus::Inspected]);

test('a second IN is refused while the room is being cleaned, and OUT is refused with no IN', function () {
    $setup = cleaningSetup();
    $message = HousekeepingRefusal::CleaningNotStarted->message($setup['room']->number);

    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/finish'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message]);

    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/start'));
    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/start'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => HousekeepingRefusal::CleaningInProgress->message($setup['room']->number)]);

    expect(RoomCleaning::query()->count())->toBe(1);
});

test('a room can be cleaned twice on one day', function () {
    $setup = cleaningSetup();

    foreach ([1, 2] as $round) {
        $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/start'));
        $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/finish'));
    }

    expect(RoomCleaning::query()->count())->toBe(2);
});

test('Start is refused on a blocked room', function () {
    $setup = cleaningSetup();
    RoomBlock::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-15']);

    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleaning/start'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => HousekeepingRefusal::OutOfService->message($setup['room']->number)]);

    expect(RoomCleaning::query()->count())->toBe(0);
});

test('front office Start names the room assignee; housekeeping Start always names themselves and takes the room', function () {
    $setup = cleaningSetup();
    $setup['room']->status->forceFill(['assigned_to' => $setup['housekeeper']->id])->save();

    $this->actingAs($setup['frontOffice'])->post(cleaningUrl($setup, 'cleaning/start'));
    expect(RoomCleaning::query()->sole()->housekeeper_id)->toBe($setup['housekeeper']->id);
    RoomCleaning::query()->delete();

    $colleague = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::HousekeepingView, PermissionEnum::HousekeepingUpdate])->create();
    $this->actingAs($colleague)->post(cleaningUrl($setup, 'cleaning/start'), ['housekeeper_id' => $setup['housekeeper']->id]);

    expect(RoomCleaning::query()->sole()->housekeeper_id)->toBe($colleague->id)
        ->and($setup['room']->status->fresh()->assigned_to)->toBe($colleague->id)
        ->and(Activity::query()->where('event', 'room_status.assigned')->count())->toBe(1);
});

test('front office must pick who is cleaning an unassigned room, and the pick becomes the assignee', function () {
    $setup = cleaningSetup();

    $this->actingAs($setup['frontOffice'])->post(cleaningUrl($setup, 'cleaning/start'))
        ->assertSessionHasErrors('housekeeper_id');
    expect(RoomCleaning::query()->count())->toBe(0);

    $cannotClean = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::HousekeepingView])->create();
    $this->actingAs($setup['frontOffice'])->post(cleaningUrl($setup, 'cleaning/start'), ['housekeeper_id' => $cannotClean->id])
        ->assertSessionHasErrors('housekeeper_id');
    expect(RoomCleaning::query()->count())->toBe(0);

    $this->actingAs($setup['frontOffice'])->post(cleaningUrl($setup, 'cleaning/start'), ['housekeeper_id' => $setup['housekeeper']->id])
        ->assertSessionHasNoErrors();

    expect(RoomCleaning::query()->sole()->housekeeper_id)->toBe($setup['housekeeper']->id)
        ->and($setup['room']->status->fresh()->assigned_to)->toBe($setup['housekeeper']->id);
});

test('front office correct the housekeeper and times in the property time zone, and the log keeps the old values', function () {
    $setup = cleaningSetup();
    $cleaning = RoomCleaning::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'date' => '2026-10-14', 'housekeeper_id' => $setup['frontOffice']->id, 'created_by' => $setup['frontOffice']->id]);

    $this->actingAs($setup['frontOffice'])->put(cleaningUrl($setup, 'cleanings/'.$cleaning->id), [
        'housekeeper_id' => $setup['housekeeper']->id,
        'in_time' => '10:05',
        'out_time' => '10:40',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $fresh = $cleaning->fresh();
    $activity = Activity::query()->where('event', 'room_cleaning.edited')->sole();

    expect($fresh->housekeeper_id)->toBe($setup['housekeeper']->id)
        ->and($fresh->started_at->toIso8601String())->toBe('2026-10-14T02:05:00+00:00')
        ->and($fresh->finished_at->toIso8601String())->toBe('2026-10-14T02:40:00+00:00')
        ->and($fresh->updated_by)->toBe($setup['frontOffice']->id)
        ->and($activity->properties['old']['housekeeper_id'])->toBe($setup['frontOffice']->id);
});

test('correcting finished times does not touch the room status, and giving an open cleaning its OUT does', function () {
    $setup = cleaningSetup();
    $finished = RoomCleaning::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id]);
    $payload = ['housekeeper_id' => $setup['housekeeper']->id, 'in_time' => '10:00', 'out_time' => '10:30'];

    $this->actingAs($setup['frontOffice'])->put(cleaningUrl($setup, 'cleanings/'.$finished->id), $payload);
    expect($setup['room']->status->fresh()->status)->toBe(HousekeepingStatus::Dirty);

    $open = RoomCleaning::factory()->open()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id]);

    $this->actingAs($setup['frontOffice'])->put(cleaningUrl($setup, 'cleanings/'.$open->id), $payload);
    expect($setup['room']->status->fresh()->status)->toBe(HousekeepingStatus::Clean);
});

test('front office add a missed cleaning, and OUT before IN or a second open one is refused', function () {
    $setup = cleaningSetup();
    $url = cleaningUrl($setup, 'cleanings');

    $this->actingAs($setup['frontOffice'])->post($url, ['housekeeper_id' => $setup['housekeeper']->id, 'in_time' => '11:00', 'out_time' => '10:00'])
        ->assertSessionHasErrors('out_time');
    expect(RoomCleaning::query()->count())->toBe(0);

    $this->actingAs($setup['frontOffice'])->post($url, ['housekeeper_id' => $setup['housekeeper']->id, 'in_time' => '09:00', 'out_time' => ''])->assertSessionHasNoErrors();
    expect(RoomCleaning::query()->sole()->finished_at)->toBeNull();

    $this->actingAs($setup['frontOffice'])->post($url, ['housekeeper_id' => $setup['housekeeper']->id, 'in_time' => '10:00', 'out_time' => ''])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => HousekeepingRefusal::CleaningInProgress->message($setup['room']->number)]);
    expect(RoomCleaning::query()->count())->toBe(1);
});

test('removing a cleaning logs what it held and leaves the room status', function () {
    $setup = cleaningSetup();
    $cleaning = RoomCleaning::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id]);

    $this->actingAs($setup['frontOffice'])->delete(cleaningUrl($setup, 'cleanings/'.$cleaning->id))->assertRedirect();

    expect(RoomCleaning::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'room_cleaning.removed')->sole()->properties['old']['housekeeper_id'])->toBe($setup['housekeeper']->id)
        ->and($setup['room']->status->fresh()->status)->toBe(HousekeepingStatus::Dirty);
});

test('housekeeping may record IN and OUT but not correct times', function () {
    $setup = cleaningSetup();
    $cleaning = RoomCleaning::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id]);
    $payload = ['housekeeper_id' => $setup['housekeeper']->id, 'in_time' => '10:00', 'out_time' => '10:30'];

    $this->actingAs($setup['housekeeper'])->put(cleaningUrl($setup, 'cleanings/'.$cleaning->id), $payload)->assertForbidden();
    $this->actingAs($setup['housekeeper'])->post(cleaningUrl($setup, 'cleanings'), $payload)->assertForbidden();
    $this->actingAs($setup['housekeeper'])->delete(cleaningUrl($setup, 'cleanings/'.$cleaning->id))->assertForbidden();
});

test('a user with neither permission records nothing, and a room or cleaning of another property is a 404', function () {
    $setup = cleaningSetup();
    $viewer = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::HousekeepingView])->create();
    $this->actingAs($viewer)->post(cleaningUrl($setup, 'cleaning/start'))->assertForbidden();

    $other = cleaningSetup();
    $this->actingAs($setup['frontOffice'])->post("/p/{$setup['property']->code}/housekeeping/rooms/{$other['room']->id}/cleaning/start")->assertNotFound();

    $foreign = RoomCleaning::factory()->create(['property_id' => $other['property']->id, 'room_id' => $other['room']->id, 'housekeeper_id' => $other['housekeeper']->id, 'created_by' => $other['housekeeper']->id]);
    $this->actingAs($setup['frontOffice'])->delete(cleaningUrl($setup, 'cleanings/'.$foreign->id))->assertNotFound();
    expect($foreign->fresh())->not->toBeNull();
});

test('a housekeeper of another property or a deactivated one cannot be named', function () {
    $setup = cleaningSetup();
    $other = cleaningSetup();
    $left = User::factory()->for($setup['property'])->create(['deactivated_at' => now()]);

    foreach ([$other['housekeeper'], $left] as $person) {
        $this->actingAs($setup['frontOffice'])->post(cleaningUrl($setup, 'cleanings'), ['housekeeper_id' => $person->id, 'in_time' => '10:00', 'out_time' => '10:30'])
            ->assertSessionHasErrors('housekeeper_id');
    }
});

test('the database keeps one open cleaning per room and OUT after IN', function () {
    $setup = cleaningSetup();
    $attributes = ['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id];
    RoomCleaning::factory()->open()->create($attributes);

    expect(fn () => RoomCleaning::factory()->open()->create($attributes))->toThrow(QueryException::class)
        ->and(fn () => RoomCleaning::factory()->create([...$attributes, 'started_at' => now(), 'finished_at' => now()->subMinute()]))->toThrow(QueryException::class);
});

test('the board shows the cleaning in the property time zone and what the viewer may do', function () {
    $setup = cleaningSetup();
    RoomCleaning::factory()->open()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'date' => '2026-10-14', 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id, 'started_at' => '2026-10-14 02:05:00']);

    $this->actingAs($setup['housekeeper'])->get("/p/{$setup['property']->code}/housekeeping")
        ->assertInertia(fn (Assert $page) => $page
            ->where('rooms.0.cleaning.cleaning.housekeeperName', 'Ana')
            ->where('rooms.0.cleaning.cleaning.inTime', '10:05')
            ->where('rooms.0.cleaning.cleaning.outTime', null)
            ->where('rooms.0.cleaning.canStart', false)
            ->where('rooms.0.cleaning.canFinish', true)
            ->where('rooms.0.cleaning.canEdit', false)
            ->where('rooms.0.cleaning.askHousekeeper', false));

    RoomCleaning::query()->delete();

    $this->actingAs($setup['frontOffice'])->get("/p/{$setup['property']->code}/housekeeping")
        ->assertInertia(fn (Assert $page) => $page->where('rooms.0.cleaning.canEdit', true)
            ->where('rooms.0.cleaning.askHousekeeper', true));

    $this->actingAs($setup['housekeeper'])->get("/p/{$setup['property']->code}/housekeeping")
        ->assertInertia(fn (Assert $page) => $page->where('rooms.0.cleaning.askHousekeeper', false));
});

test('a cleaning left open on an earlier date still shows, so someone can record its OUT', function () {
    $setup = cleaningSetup();
    RoomCleaning::factory()->open()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'date' => '2026-10-13', 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id]);

    $latest = app(RoomCleanings::class)->latestByRoom($setup['property'], '2026-10-14');

    expect($latest->get($setup['room']->id)?->date->toDateString())->toBe('2026-10-13');
});

test('the housekeeping log lists who cleaned which room between two dates, and its export keeps to them', function () {
    $setup = cleaningSetup();
    $reader = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReportsView, PermissionEnum::HousekeepingEditTimes])->create();
    $attributes = ['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id];
    RoomCleaning::factory()->create([...$attributes, 'date' => '2026-10-14', 'started_at' => '2026-10-14 02:05:00', 'finished_at' => '2026-10-14 02:40:00']);
    RoomCleaning::factory()->create([...$attributes, 'date' => '2026-10-01', 'started_at' => '2026-10-01 02:00:00', 'finished_at' => '2026-10-01 02:10:00']);

    $this->actingAs($reader)->get("/p/{$setup['property']->code}/reports/housekeeping?tab=log&from=2026-10-14&to=2026-10-14")
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/housekeeping')
            ->where('tab', 'log')
            ->has('rows.data', 1)
            ->where('rows.data.0.room', $setup['room']->number)
            ->where('rows.data.0.housekeeper', 'Ana')
            ->where('rows.data.0.in', '10:05')
            ->where('rows.data.0.out', '10:40')
            ->where('rows.data.0.minutes', 35));
});

test('House status shows the housekeeper and times on the room tile', function () {
    $setup = cleaningSetup();
    $reader = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReportsView, PermissionEnum::HousekeepingEditTimes])->create();
    RoomBlock::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-15', 'reason' => 'owner_use']);
    RoomCleaning::factory()->create(['property_id' => $setup['property']->id, 'room_id' => $setup['room']->id, 'date' => '2026-10-14', 'housekeeper_id' => $setup['housekeeper']->id, 'created_by' => $setup['housekeeper']->id, 'started_at' => '2026-10-14 02:05:00', 'finished_at' => '2026-10-14 02:40:00']);

    $this->actingAs($reader)->get("/p/{$setup['property']->code}/reports/house-status?date=2026-10-14")
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.data.0.cleaning.cleaning.housekeeperName', 'Ana')
            ->where('rows.data.0.cleaning.cleaning.outTime', '10:40')
            ->where('rows.data.0.cleaning.canEdit', true)
            ->where('rows.data.0.cleaning.canStart', false));
});
