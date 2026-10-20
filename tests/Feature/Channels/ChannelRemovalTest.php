<?php

use App\Enums\ChannelAlertEvent;
use App\Enums\ChannelRemovalKind;
use App\Jobs\ChannelRemovalJob;
use App\Models\ChannelAlert;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\User;
use App\Notifications\ChannelRemovalBlocked;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/ChannelStructure.php';

/** An owner, a synced property, and two room types with one plan, all on Channex. */
function channelRemovalScene(): array
{
    config(['services.channex.base_url' => 'https://staging.channex.io/api/v1', 'services.channex.api_key' => 'secret-key']);
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $channelProperty = channelSyncedProperty($property);
    $deluxe = channelRoomType($property, 'Deluxe King');
    $suite = channelRoomType($property, 'Suite');
    $standard = channelRatePlan($property, [$deluxe, $suite], 'Standard');
    $breakfast = channelRatePlan($property, [$deluxe, $suite], 'Breakfast');
    $mappings = [];

    foreach ([$deluxe, $suite] as $roomType) {
        $mappings['room'][$roomType->id] = ChannelRoomTypeMapping::factory()->for($channelProperty)->create(['room_type_id' => $roomType->id]);

        foreach ([$standard, $breakfast] as $plan) {
            $mappings['plan'][$plan->id.':'.$roomType->id] = ChannelRatePlanMapping::factory()->for($channelProperty)->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id]);
        }
    }

    return [$owner, $property, $channelProperty, $deluxe, $suite, $standard, $breakfast, $mappings];
}

/**
 * Channex as it answers a removal: the plan belongs to the property, the property has the given channels (each a list
 * of the Channex rate plan ids it maps), and a DELETE answers with the status.
 *
 * @param  array<string, list<string>>  $channels  Channel title => mapped Channex rate plan ids.
 * @param  array<string, string>  $parents  Channex rate plan id => the id of its parent plan (a plan Channex made from ours).
 */
function channexAnswersRemoval(int $deleteStatus = 200, array $channels = [], array $parents = []): void
{
    Http::fake(function ($request) use ($deleteStatus, $channels, $parents) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'DELETE' => $deleteStatus === 200
                ? Http::response(['meta' => ['message' => 'Success']], 200)
                : Http::response(['errors' => ['code' => 'validation_error', 'details' => ['base' => ['is used by a channel']]]], $deleteStatus),
            $request->method() === 'GET' && str_contains($path, '/channels') => Http::response(['data' => array_map(
                fn (string $title, array $planIds): array => ['id' => "channel-{$title}", 'attributes' => ['title' => $title, 'rate_plans' => array_map(fn (string $id): array => ['id' => "map-{$id}", 'rate_plan_id' => $id], $planIds)]],
                array_keys($channels),
                array_values($channels),
            )], 200),
            $request->method() === 'GET' => Http::response(['data' => ['id' => basename($path), 'relationships' => [
                'property' => ['data' => ['id' => 'properties-1']],
                ...(isset($parents[basename($path)]) ? ['parent_rate_plan' => ['data' => ['id' => $parents[basename($path)]]]] : []),
            ]]], 200),
            default => Http::response(['data' => []], 200),
        };
    });
}

function channexRefusesDeletes(int $status = 422): void
{
    channexAnswersRemoval($status);
}

test('a rate plan is removed from Channex, then archived', function () {
    [$owner, , , , , , $breakfast] = channelRemovalScene();
    fakeChannex();

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id);

    expect($breakfast->refresh()->archived_at)->not->toBeNull()
        ->and(ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->count())->toBe(0)
        ->and(ChannelRatePlanMapping::query()->count())->toBe(2)
        ->and(collect(channexRequests())->filter(fn ($request) => $request->method() === 'DELETE')->count())->toBe(2)
        ->and(collect(channexRequests())->contains(fn ($request) => str_contains($request->url(), 'force')))->toBeFalse();
});

test('a plan that an OTA channel has mapped is never deleted, because Channex would drop the mapping', function () {
    Notification::fake();
    [$owner, , $channelProperty, , , , $breakfast] = channelRemovalScene();
    $mappedId = ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->first()->external_rate_plan_id;
    channexAnswersRemoval(channels: ['Booking.com' => [$mappedId]]);

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id);

    expect($breakfast->refresh()->archived_at)->toBeNull()
        ->and(ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->count())->toBe(2)
        ->and(collect(channexRequests())->filter(fn ($request) => $request->method() === 'DELETE')->count())->toBe(0)
        ->and(ChannelAlert::query()->sole()->event)->toBe(ChannelAlertEvent::RemovalBlocked)
        ->and(ChannelAlert::query()->sole()->channel_property_id)->toBe($channelProperty->id);
    Notification::assertSentTo($owner, ChannelRemovalBlocked::class);
});

test('a plan is not deleted when a channel maps a plan Channex made from it', function () {
    Notification::fake();
    [$owner, , , , , , $breakfast] = channelRemovalScene();
    $ourId = ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->first()->external_rate_plan_id;
    channexAnswersRemoval(channels: ['Booking.com' => ['hidden-child']], parents: ['hidden-child' => $ourId]);

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id);

    expect($breakfast->refresh()->archived_at)->toBeNull()
        ->and(collect(channexRequests())->filter(fn ($request) => $request->method() === 'DELETE')->count())->toBe(0)
        ->and(ChannelAlert::query()->sole()->event)->toBe(ChannelAlertEvent::RemovalBlocked);
});

test('a plan is not deleted when a channel maps a grandchild of it', function () {
    [$owner, , , , , , $breakfast] = channelRemovalScene();
    $ourId = ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->first()->external_rate_plan_id;
    channexAnswersRemoval(channels: ['Booking.com' => ['grandchild']], parents: ['grandchild' => 'child', 'child' => $ourId]);

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id);

    expect($breakfast->refresh()->archived_at)->toBeNull();
});

test('a plan that no channel maps is removed even when other plans are mapped', function () {
    [$owner, , , , , , $breakfast] = channelRemovalScene();
    channexAnswersRemoval(channels: ['Booking.com' => ['some-other-plan']]);

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id);

    expect($breakfast->refresh()->archived_at)->not->toBeNull();
});

test('a room type is removed after its rate plans, then archived', function () {
    [$owner, , , $deluxe, , , , $mappings] = channelRemovalScene();
    fakeChannex();

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RoomType, $deluxe->id, $owner->id);

    $deletes = collect(channexRequests())->filter(fn ($request) => $request->method() === 'DELETE')->map(fn ($request) => $request->url())->values();
    expect($deluxe->refresh()->archived_at)->not->toBeNull()
        ->and($deletes)->toHaveCount(3)
        ->and($deletes->last())->toEndWith('/room_types/'.$mappings['room'][$deluxe->id]->external_room_type_id)
        ->and(ChannelRoomTypeMapping::query()->where('room_type_id', $deluxe->id)->exists())->toBeFalse();
});

test('unlinking a room type removes its pair from Channex, then unlinks it', function () {
    [$owner, , , $deluxe, $suite, , $breakfast] = channelRemovalScene();
    fakeChannex();

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::Unlink, $breakfast->id, $owner->id, [$suite->id], [$deluxe->id]);

    expect($breakfast->roomTypes()->pluck('room_types.id')->all())->toBe([$suite->id])
        ->and(ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->where('room_type_id', $deluxe->id)->exists())->toBeFalse()
        ->and(ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->where('room_type_id', $suite->id)->exists())->toBeTrue();
});

test('an OTA that still uses the plan stops the archive, tells the admins and tells the staff member', function () {
    Notification::fake();
    [$owner, $property, $channelProperty, , , , $breakfast] = channelRemovalScene();
    channexRefusesDeletes();

    ChannelRemovalJob::dispatchSync(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id);

    $alert = ChannelAlert::query()->sole();
    expect($breakfast->refresh()->archived_at)->toBeNull()
        ->and(ChannelRatePlanMapping::query()->where('rate_plan_id', $breakfast->id)->count())->toBe(2)
        ->and($alert->event)->toBe(ChannelAlertEvent::RemovalBlocked)
        ->and($alert->channel_property_id)->toBe($channelProperty->id)
        ->and($alert->payload['message'])->toContain('Breakfast');
    Notification::assertSentTo($owner, ChannelRemovalBlocked::class, fn (ChannelRemovalBlocked $notification) => $notification->name === 'Breakfast' && $notification->propertyCode === $property->code);
});

test('a rate limit tries again later and changes nothing', function () {
    Notification::fake();
    [$owner, , , , , , $breakfast] = channelRemovalScene();
    channexRefusesDeletes(429);

    app()->call([new ChannelRemovalJob(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id), 'handle']);

    expect($breakfast->refresh()->archived_at)->toBeNull()
        ->and(ChannelAlert::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('the same removal cannot be queued twice, and a pair or plan not on Channex needs none', function () {
    Queue::fake();
    [$owner, $property, , $deluxe, , $standard, $breakfast] = channelRemovalScene();
    $this->actingAs($owner);

    $this->post(route('settings.rate-plans.archive', [$property->code, $breakfast]))->assertRedirect();
    $this->post(route('settings.rate-plans.archive', [$property->code, $breakfast]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Breakfast is already being removed from the channels.']);
    Queue::assertPushed(ChannelRemovalJob::class, 1);

    ChannelRatePlanMapping::query()->where('rate_plan_id', $standard->id)->delete();
    $this->post(route('settings.rate-plans.archive', [$property->code, $standard]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Standard archived.']);
    Queue::assertPushed(ChannelRemovalJob::class, 1);
});

test('without a Channex key the archive is refused and nothing is queued', function () {
    Queue::fake();
    [$owner, $property, , , , , $breakfast] = channelRemovalScene();
    config(['services.channex.api_key' => null]);

    $this->actingAs($owner)->post(route('settings.rate-plans.archive', [$property->code, $breakfast]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Channex is not set up on this server.']);

    Queue::assertNothingPushed();
});

test('the staff member who asked sees pending, then the result, in the shared props', function () {
    Queue::fake();
    [$owner, $property, , , , , $breakfast] = channelRemovalScene();
    $this->actingAs($owner);

    $this->post(route('settings.rate-plans.archive', [$property->code, $breakfast]))->assertRedirect();
    $this->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn ($page) => $page->has('channelRemovals', 1)->where('channelRemovals.0.state', 'pending')->where('channelRemovals.0.name', 'Breakfast')->where('channelRemovals.0.kind', 'rate_plan'));

    fakeChannex();
    app()->call([new ChannelRemovalJob(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id), 'handle']);

    $this->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn ($page) => $page->has('channelRemovals', 1)->where('channelRemovals.0.state', 'done')->where('channelRemovals.0.message', 'Breakfast archived.'));
});

test('a blocked removal shows as not done, with why, and only to the staff member who asked', function () {
    Queue::fake();
    [$owner, $property, , , , , $breakfast] = channelRemovalScene();
    $other = User::factory()->for($property)->create();
    channexRefusesDeletes();
    $this->actingAs($owner)->post(route('settings.rate-plans.archive', [$property->code, $breakfast]));

    app()->call([new ChannelRemovalJob(ChannelRemovalKind::RatePlan, $breakfast->id, $owner->id), 'handle']);

    $this->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn ($page) => $page->where('channelRemovals.0.state', 'blocked')->where('channelRemovals.0.message', fn (string $message) => str_contains($message, 'still used by an OTA')));
    $this->actingAs($other)->get(route('dashboard', $property->code))
        ->assertInertia(fn ($page) => $page->where('channelRemovals', []));
});
