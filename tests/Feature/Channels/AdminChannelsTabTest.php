<?php

use App\Enums\ChannelPropertyStatus;
use App\Features\ChannelManager;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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

test('an admin syncs the structure and sees a toast', function () {
    $property = channelOn(channelProperty());
    $roomType = channelRoomType($property);
    channelRatePlan($property, [$roomType]);
    fakeChannex();

    $this->actingAs(channelAdmin())
        ->post(route('admin.properties.channels.sync', $property->code))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 room types and 1 rate plans sent to Channex.']);

    expect(ChannelProperty::query()->where('property_id', $property->id)->exists())->toBeTrue();
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
