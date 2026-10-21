<?php

use App\Actions\Channels\IngestChannelBooking;
use App\Actions\Channels\RecordChannelSyncProgress;
use App\Actions\Channels\SyncChannelStructure;
use App\Enums\ChannelAlertEvent;
use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelSyncState;
use App\Features\ChannelManager;
use App\Jobs\FlushChannelAriJob;
use App\Jobs\SyncChannelStructureJob;
use App\Models\ChannelAlert;
use App\Models\ChannelAriChange;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Channels\ChannelSyncProgress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelStructure.php';

function channelAdmin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

function channelOn(Property $property): Property
{
    Feature::for($property)->activate(ChannelManager::class);

    return $property;
}

test('the Channels tab is a 404 while the feature is off, and the other tabs do not show it', function () {
    $property = channelProperty();

    $this->actingAs(channelAdmin())->get(route('admin.properties.channels', $property->code))->assertNotFound();
    $this->get(route('admin.properties.features', $property->code))->assertInertia(fn (Assert $page) => $page->where('channelManagerOn', false));
});

test('staff cannot open the Channels tab', function () {
    $property = channelOn(channelProperty());

    $this->actingAs(User::factory()->create())->get(route('admin.properties.channels', $property->code))->assertNotFound();
});

test('a property that is not synced shows the preview and the Not synced state', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType], 'Standard');
    channelRatePlan($property, [$roomType], 'Hourly', 'dayUse');

    $this->actingAs(channelAdmin())
        ->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'channels')
            ->where('channelManagerOn', true)
            ->where('channel.status.value', 'not_synced')
            ->where('channel.synced', [])
            ->where('channel.preview.roomTypes', 1)
            ->where('channel.preview.ratePlans', 1)
            ->where('channel.preview.skipped', 1)
            ->where('channel.preview.blockedReason', null)
            ->where('channel.preview.rows.0.roomType', 'Deluxe King')
            ->where('channel.preview.rows.0.support.value', 'full')
            ->where('channel.preview.rows.1.support.value', 'unsupported')
            ->where('channel.preview.rows.1.detail', 'Day use plans are sold at the front desk only.'));
});

test('a synced property lists what Channex has', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    $plan = channelRatePlan($property, [$roomType], 'Standard');
    $channelProperty = channelSyncedProperty($property);
    $roomMapping = ChannelRoomTypeMapping::factory()->for($channelProperty)->create(['room_type_id' => $roomType->id]);
    $planMapping = ChannelRatePlanMapping::factory()->for($channelProperty)->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id]);

    $this->actingAs(channelAdmin())
        ->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.status.value', 'synced')
            ->where('channel.synced', [[
                'mappingId' => $roomMapping->id,
                'name' => 'Deluxe King',
                'ratePlans' => [['id' => $planMapping->id, 'name' => 'Standard']],
            ]]));
});

test('an admin starts the sync, which is queued and gives no toast', function () {
    Queue::fake();
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    Http::fake();

    $this->actingAs(channelAdmin())
        ->post(route('admin.properties.channels.sync', $property->code))
        ->assertRedirect();

    Queue::assertPushed(SyncChannelStructureJob::class, fn (SyncChannelStructureJob $job) => $job->propertyId === $property->id);
    expect(channexCalls())->toBe([]);
    $this->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('channel.sync.state', 'queued'));
});

test('the sync job syncs the structure and reports each step until it is done', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    fakeChannex();
    $admin = channelAdmin();
    app(RecordChannelSyncProgress::class)($property->id, ChannelSyncState::Queued);

    (new SyncChannelStructureJob($property->id, $admin->id))->handle(app(SyncChannelStructure::class), app(RecordChannelSyncProgress::class));

    expect(ChannelProperty::query()->where('property_id', $property->id)->exists())->toBeTrue();
    $this->actingAs($admin)->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.status.value', 'synced')
            ->where('channel.sync.state', 'done')
            ->where('channel.sync.message', '1 room types and 1 rate plans sent to Channex.'));
});

test('the sync job reports steps while it runs', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    fakeChannex();
    $steps = [];
    $record = new class($steps) extends RecordChannelSyncProgress
    {
        public function __construct(public array &$steps) {}

        public function __invoke(int $propertyId, ChannelSyncState $state, int $done = 0, int $total = 0, ?string $message = null): void
        {
            $this->steps[] = [$state->value, $done, $total];
            parent::__invoke($propertyId, $state, $done, $total, $message);
        }
    };

    (new SyncChannelStructureJob($property->id, channelAdmin()->id))->handle(app(SyncChannelStructure::class), $record);

    expect($steps)->toBe([['running', 0, 0], ['running', 1, 3], ['running', 2, 3], ['running', 3, 3], ['done', 0, 0]]);
});

test('a second start is refused while a sync waits or runs, and a lost sync does not block', function () {
    Queue::fake();
    $property = channelOn(channelProperty());
    channelRatePlan($property, [channelRoomType($property)]);
    $admin = channelAdmin();
    $this->actingAs($admin);

    $this->post(route('admin.properties.channels.sync', $property->code))->assertRedirect();
    $this->post(route('admin.properties.channels.sync', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A sync of this property is already running.']);
    Queue::assertPushed(SyncChannelStructureJob::class, 1);

    $this->travel(20)->minutes();
    $this->post(route('admin.properties.channels.sync', $property->code))->assertRedirect();
    Queue::assertPushed(SyncChannelStructureJob::class, 2);
});

test('a job that crashes records the failure so the button works again', function () {
    $property = channelOn(channelProperty());

    (new SyncChannelStructureJob($property->id, channelAdmin()->id))->failed(new RuntimeException('boom'));

    expect(app(ChannelSyncProgress::class)->forProperty($property->id)['state'])->toBe(ChannelSyncState::Failed);
});

test('a failed sync shows the reason as an error toast', function () {
    $property = channelOn(channelProperty());
    channelRoomType($property);
    Http::fake();

    $this->actingAs(channelAdmin())
        ->post(route('admin.properties.channels.sync', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Nothing can be sent yet. Every room type and rate plan pair is skipped.']);
});

test('sync needs two-factor, staff and the feature', function () {
    config(['estatell.support_require_two_factor' => true]);
    $property = channelOn(channelProperty());
    Http::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.properties.channels.sync', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.properties.channels.sync', $property->code))
        ->assertNotFound();

    $off = channelProperty();
    $this->actingAs(channelAdmin())->post(route('admin.properties.channels.sync', $off->code))->assertNotFound();

    expect(channexCalls())->toBeEmpty();
});

test('an admin removes a rate plan from Channex and the link is forgotten', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    $plan = channelRatePlan($property, [$roomType], 'Standard');
    $channelProperty = channelSyncedProperty($property);
    $mapping = ChannelRatePlanMapping::factory()->for($channelProperty)->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'external_rate_plan_id' => 'rp-1']);
    fakeChannex();

    $this->actingAs(channelAdmin())
        ->delete(route('admin.properties.channels.rate-plans.destroy', [$property->code, $mapping->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Standard · Deluxe King removed from Channex.']);

    expect(ChannelRatePlanMapping::query()->count())->toBe(0)
        ->and(collect(channexRequests())->contains(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/rate_plans/rp-1')))->toBeTrue()
        ->and(collect(channexRequests())->contains(fn ($request) => str_contains($request->url(), 'force')))->toBeFalse()
        ->and(Activity::query()->where('event', 'channel.rate_plan_removed')->count())->toBe(1);
});

test('Channex refusing a removal leaves the link and says so', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    $plan = channelRatePlan($property, [$roomType], 'Standard');
    $mapping = ChannelRatePlanMapping::factory()->for(channelSyncedProperty($property))->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id]);
    Http::fake(['*' => Http::response(['errors' => ['code' => 'validation_error']], 422)]);

    $this->actingAs(channelAdmin())
        ->delete(route('admin.properties.channels.rate-plans.destroy', [$property->code, $mapping->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Standard · Deluxe King could not be removed. An OTA may still use it.']);

    expect(ChannelRatePlanMapping::query()->count())->toBe(1);
});

test('a removal that Channex already did counts as removed', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    $plan = channelRatePlan($property, [$roomType], 'Standard');
    $mapping = ChannelRatePlanMapping::factory()->for(channelSyncedProperty($property))->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id]);
    Http::fake(['*' => Http::response(['errors' => ['code' => 'resource_not_found']], 404)]);

    $this->actingAs(channelAdmin())->delete(route('admin.properties.channels.rate-plans.destroy', [$property->code, $mapping->id]));

    expect(ChannelRatePlanMapping::query()->count())->toBe(0);
});

test('a room type is removed only after its rate plans', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    $plan = channelRatePlan($property, [$roomType], 'Standard');
    $channelProperty = channelSyncedProperty($property);
    $roomMapping = ChannelRoomTypeMapping::factory()->for($channelProperty)->create(['room_type_id' => $roomType->id, 'external_room_type_id' => 'rt-1']);
    $planMapping = ChannelRatePlanMapping::factory()->for($channelProperty)->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id]);
    fakeChannex();

    $this->actingAs(channelAdmin())
        ->delete(route('admin.properties.channels.room-types.destroy', [$property->code, $roomMapping->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Remove the rate plans of Deluxe King first.']);
    expect(ChannelRoomTypeMapping::query()->count())->toBe(1);

    $planMapping->delete();
    $this->delete(route('admin.properties.channels.room-types.destroy', [$property->code, $roomMapping->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Deluxe King removed from Channex.']);

    expect(ChannelRoomTypeMapping::query()->count())->toBe(0)
        ->and(collect(channexRequests())->contains(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/room_types/rt-1')))->toBeTrue();
});

test('another property\'s link is a 404', function () {
    $property = channelOn(channelProperty());
    $other = channelProperty();
    $roomType = channelRoomType($other);
    $plan = channelRatePlan($other, [$roomType]);
    $mapping = ChannelRatePlanMapping::factory()->for(channelSyncedProperty($other))->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id]);
    Http::fake();

    $this->actingAs(channelAdmin())
        ->delete(route('admin.properties.channels.rate-plans.destroy', [$property->code, $mapping->id]))
        ->assertNotFound();

    expect(ChannelRatePlanMapping::query()->count())->toBe(1)->and(channexCalls())->toBeEmpty();
});

test('the status badge of an error shows the last error on the tab', function () {
    $property = channelOn(channelProperty());
    ChannelProperty::factory()->for($property)->create(['status' => ChannelPropertyStatus::Error, 'last_error' => 'Channex refused the request (422).']);

    $this->actingAs(channelAdmin())
        ->get(route('admin.properties.channels', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.status.value', 'error')
            ->where('channel.lastError', 'Channex refused the request (422).'));
});

test('Full refresh queues the 500-day sync, is logged, and is refused inside a day', function () {
    Queue::fake();
    $setup = channelAriSetup();
    channelOn($setup['property']);
    $admin = channelAdmin();

    $this->actingAs($admin)->post(route('admin.properties.channels.full-refresh', $setup['property']->code))->assertRedirect();

    Queue::assertPushed(FlushChannelAriJob::class, fn (FlushChannelAriJob $job): bool => $job->full && $job->channelPropertyId === $setup['channelProperty']->id);
    expect(Activity::query()->where('event', 'channel.full_refresh_queued')->sole()->causer_id)->toBe($admin->id);

    $setup['channelProperty']->update(['full_synced_at' => now()->subHours(3)]);
    $this->post(route('admin.properties.channels.full-refresh', $setup['property']->code))->assertRedirect();
    Queue::assertPushed(FlushChannelAriJob::class, 1);
});

test('Full refresh is a 404 for a property with the feature off and refused when nothing is synced', function () {
    Queue::fake();
    $property = channelProperty();

    $this->actingAs(channelAdmin())->post(route('admin.properties.channels.full-refresh', $property->code))->assertNotFound();

    channelOn($property);
    $this->post(route('admin.properties.channels.full-refresh', $property->code))->assertRedirect();
    Queue::assertNothingPushed();
});

test('the tab shows when changes were sent, the pause and how many changes wait', function () {
    $setup = channelAriSetup();
    channelOn($setup['property']);
    $setup['channelProperty']->update(['changes_sent_at' => now()->subMinute(), 'paused_until' => now()->addSeconds(60)]);
    ChannelAriChange::factory()->count(2)->create(['channel_property_id' => $setup['channelProperty']->id, 'room_type_id' => $setup['roomType']->id]);

    $this->actingAs(channelAdmin())
        ->get(route('admin.properties.channels', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channel.waitingChanges', 2)
            ->where('channel.canFullRefresh', true)
            ->whereNot('channel.pausedUntil', null)
            ->whereNot('channel.changesSentAt', null));
});

require_once __DIR__.'/../Support/ChannelBooking.php';

test('the tab lists the bookings that were not saved, the open alerts and the recent bookings', function () {
    $setup = channelBookingSetup();
    channelOn($setup['property']);
    fakeChannexAck();
    $saved = storeChannelRevision($setup, ['id' => 'rev-1', 'booking_id' => 'b-1']);
    app(IngestChannelBooking::class)($saved);
    $failed = storeChannelRevision($setup, ['id' => 'rev-2', 'booking_id' => 'b-2', 'unique_id' => 'BDC-2', 'rooms' => [channelRevisionRoom(['room_type_id' => null])]]);
    app(IngestChannelBooking::class)($failed);
    $open = ChannelAlert::factory()->create(['channel_property_id' => $setup['channelProperty']->id, 'event' => ChannelAlertEvent::RateError]);
    ChannelAlert::factory()->create(['channel_property_id' => $setup['channelProperty']->id, 'resolved_at' => now()]);

    $this->actingAs(channelAdmin())
        ->get(route('admin.properties.channels', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('channel.failedBookings', 1)
            ->where('channel.failedBookings.0.id', $failed->id)
            ->where('channel.failedBookings.0.bookingCode', 'BDC-2')
            ->where('channel.failedBookings.0.error', fn (string $error) => str_contains($error, 'not mapped'))
            ->has('channel.alerts', 1)
            ->where('channel.alerts.0.id', $open->id)
            ->where('channel.alerts.0.event.value', 'rate_error')
            ->has('channel.recentBookings', 2)
            ->where('channel.recentBookings.1.reservationReference', fn (string $reference) => $reference !== '')
            ->where('channel.recentBookings.0.reservationReference', null));
});

test('an admin retries a refused booking after fixing the mapping', function () {
    $setup = channelBookingSetup();
    channelOn($setup['property']);
    fakeChannexAck();
    $setup['channelProperty']->ratePlanMappings()->delete();
    $revision = storeChannelRevision($setup);
    app(IngestChannelBooking::class)($revision);
    $setup['channelProperty']->ratePlanMappings()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'external_rate_plan_id' => 'plan-1']);

    $this->actingAs(channelAdmin())
        ->post(route('admin.properties.channels.revisions.retry', [$setup['property']->code, $revision->id]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Booking saved.']);

    expect($revision->fresh()->acknowledged_at)->not->toBeNull()
        ->and(Reservation::query()->count())->toBe(1);
    expect(Activity::query()->where('event', 'channel_revision.retried')->count())->toBe(1);
});

test('retrying a booking that is already saved is refused with a toast', function () {
    $setup = channelBookingSetup();
    channelOn($setup['property']);
    fakeChannexAck();
    $revision = storeChannelRevision($setup);
    app(IngestChannelBooking::class)($revision);

    $this->actingAs(channelAdmin())
        ->post(route('admin.properties.channels.revisions.retry', [$setup['property']->code, $revision->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The booking is already saved.']);
});

test('an admin closes an alert once, and another property alert is a 404', function () {
    $setup = channelBookingSetup();
    channelOn($setup['property']);
    $alert = ChannelAlert::factory()->create(['channel_property_id' => $setup['channelProperty']->id]);
    $other = channelOn(channelProperty());
    $otherAlert = ChannelAlert::factory()->create(['channel_property_id' => channelSyncedProperty($other, 'prop-2')->id]);

    $this->actingAs(channelAdmin())
        ->post(route('admin.properties.channels.alerts.resolve', [$setup['property']->code, $alert->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alert closed.']);
    $this->post(route('admin.properties.channels.alerts.resolve', [$setup['property']->code, $alert->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The alert is already closed.']);
    $this->post(route('admin.properties.channels.alerts.resolve', [$setup['property']->code, $otherAlert->id]))->assertNotFound();

    expect($alert->fresh()->resolved_at)->not->toBeNull()
        ->and($otherAlert->fresh()->resolved_at)->toBeNull();
});

test('the booking routes need two-factor and the feature', function () {
    config(['estatell.support_require_two_factor' => true]);
    $setup = channelBookingSetup();
    $revision = storeChannelRevision($setup);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.properties.channels.revisions.retry', [$setup['property']->code, $revision->id]))
        ->assertNotFound();

    channelOn($setup['property']);
    $this->post(route('admin.properties.channels.revisions.retry', [$setup['property']->code, $revision->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);
    expect($revision->fresh()->processed_at)->toBeNull();
});
