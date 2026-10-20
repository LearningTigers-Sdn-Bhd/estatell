<?php

use App\Actions\Channels\RecordChannelSyncProgress;
use App\Actions\Channels\ScheduleChannelStructureSync;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Enums\ChannelAlertEvent;
use App\Enums\ChannelSyncState;
use App\Events\ChannelStructureChanged;
use App\Features\ChannelManager;
use App\Jobs\AutoSyncChannelStructureJob;
use App\Models\ChannelAlert;
use App\Models\User;
use App\Services\Channels\ChannelSyncProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelStructure.php';

/** A property that is on Channex with the channel manager on, one room type and one plan. */
function autoSyncScene(): array
{
    $property = channelProperty();
    Feature::for($property)->activate(ChannelManager::class);
    $roomType = channelRoomType($property);
    $ratePlan = channelRatePlan($property, [$roomType], 'Standard');
    $channelProperty = channelSyncedProperty($property);
    $channelProperty->roomTypeMappings()->create(['room_type_id' => $roomType->id, 'external_room_type_id' => 'room-1']);
    $channelProperty->ratePlanMappings()->create(['room_type_id' => $roomType->id, 'rate_plan_id' => $ratePlan->id, 'external_rate_plan_id' => 'plan-1']);

    return [$property, $channelProperty, $roomType, $ratePlan];
}

test('a change schedules one sync a couple of minutes later, and each change starts the wait again', function () {
    Queue::fake();
    [$property] = autoSyncScene();

    app(ScheduleChannelStructureSync::class)(new ChannelStructureChanged($property->id));
    $first = Cache::get(ScheduleChannelStructureSync::key($property->id));
    app(ScheduleChannelStructureSync::class)(new ChannelStructureChanged($property->id));
    $second = Cache::get(ScheduleChannelStructureSync::key($property->id));

    expect($second)->not->toBe($first)
        ->and(app(ChannelSyncProgress::class)->isWaiting($property->id))->toBeTrue();
    Queue::assertPushed(AutoSyncChannelStructureJob::class, 2);
    Queue::assertPushed(AutoSyncChannelStructureJob::class, fn (AutoSyncChannelStructureJob $job) => $job->token === $second && $job->delay?->diffInMinutes(now(), true) >= 1);
});

test('only the job of the latest change syncs, and it syncs once', function () {
    [$property] = autoSyncScene();
    fakeChannex();
    Cache::put(ScheduleChannelStructureSync::key($property->id), 'latest', now()->addMinutes(30));

    AutoSyncChannelStructureJob::dispatchSync($property->id, 'older');
    expect(channexCalls())->toBe([]);

    AutoSyncChannelStructureJob::dispatchSync($property->id, 'latest');

    expect(channexCalls())->not->toBe([])
        ->and(Cache::has(ScheduleChannelStructureSync::key($property->id)))->toBeFalse()
        ->and(app(ChannelSyncProgress::class)->forProperty($property->id)['state'])->toBe(ChannelSyncState::Done);
});

test('a property that is not on Channex yet, or has the channel manager off, schedules nothing', function () {
    Queue::fake();
    $notSynced = channelProperty();
    Feature::for($notSynced)->activate(ChannelManager::class);
    [$off] = autoSyncScene();
    Feature::for($off)->deactivate(ChannelManager::class);

    app(ScheduleChannelStructureSync::class)(new ChannelStructureChanged($notSynced->id));
    app(ScheduleChannelStructureSync::class)(new ChannelStructureChanged($off->id));

    Queue::assertNothingPushed();
});

test('a sync that is already running makes the automatic one wait', function () {
    [$property] = autoSyncScene();
    fakeChannex();
    Cache::put(ScheduleChannelStructureSync::key($property->id), 'token', now()->addMinutes(30));
    app(RecordChannelSyncProgress::class)($property->id, ChannelSyncState::Running, 1, 5);

    AutoSyncChannelStructureJob::dispatchSync($property->id, 'token');

    expect(channexCalls())->toBe([])
        ->and(Cache::get(ScheduleChannelStructureSync::key($property->id)))->toBe('token');
});

test('a failed automatic sync records an alert for the admins and the failed state', function () {
    [$property] = autoSyncScene();
    Http::fake(['*' => Http::response(['errors' => ['code' => 'validation_error', 'details' => ['title' => ['is taken']]]], 422)]);
    Cache::put(ScheduleChannelStructureSync::key($property->id), 'token', now()->addMinutes(30));

    AutoSyncChannelStructureJob::dispatchSync($property->id, 'token');

    $alert = ChannelAlert::query()->sole();
    expect($alert->event)->toBe(ChannelAlertEvent::StructureSyncFailed)
        ->and($alert->payload['message'])->toContain('422')
        ->and(app(ChannelSyncProgress::class)->forProperty($property->id)['state'])->toBe(ChannelSyncState::Failed);
});

test('the automatic sync has no admin and still saves the structure', function () {
    Queue::fake();
    [$property, , $roomType] = autoSyncScene();
    $property->channelProperty->update(['external_property_id' => 'prop-1']);
    fakeChannex();
    $roomType->update(['name' => 'Deluxe King Plus']);
    Cache::put(ScheduleChannelStructureSync::key($property->id), 'token', now()->addMinutes(30));

    app()->call([new AutoSyncChannelStructureJob($property->id, 'token'), 'handle']);

    $update = collect(channexRequests())->first(fn ($request) => $request->method() === 'PUT' && str_contains($request->url(), '/room_types/'));
    expect($update->data()['room_type']['title'])->toBe('Deluxe King Plus')
        ->and(Activity::query()->where('event', 'channel.structure_synced')->sole()->causer_id)->toBeNull();
});

test('renaming a plan, adding a room type or a plan, and linking a plan each schedule a sync, and a plain edit does not', function () {
    Queue::fake();
    [$property, , $roomType, $ratePlan] = autoSyncScene();
    $owner = User::factory()->for($property)->owner()->create();
    $ratePlan = $ratePlan->fresh();
    $roomType = $roomType->fresh();
    $pushed = fn (): int => Queue::pushed(AutoSyncChannelStructureJob::class)->count();
    $before = $pushed();

    $ratePlan->update(['name' => 'Standard rate']);
    expect($pushed() - $before)->toBe(1);

    $ratePlan->update(['description' => 'A plain edit that Channex does not show.']);
    expect($pushed() - $before)->toBe(1);

    $suite = channelRoomType($property, 'Suite');
    expect($pushed() - $before)->toBe(2);

    $roomType->update(['max_adults' => 4]);
    expect($pushed() - $before)->toBe(3);

    app(SyncRatePlanRoomTypes::class)($owner, $ratePlan->refresh(), [$roomType->id, $suite->id]);
    expect($pushed() - $before)->toBe(4);
});

test('the Channels tab says changes are waiting', function () {
    [$property] = autoSyncScene();
    Queue::fake();
    app(ScheduleChannelStructureSync::class)(new ChannelStructureChanged($property->id));

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn ($page) => $page->where('channel.sync.waiting', true)->where('channel.sync.state', 'idle'));
});
