<?php

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rooms\ArchiveRoomType;
use App\Enums\ChannelRemovalKind;
use App\Jobs\ChannelRemovalJob;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/ChannelStructure.php';

/** An owner whose property has two room types and two plans, with one pair synced to Channex. */
function channelGuardScene(): array
{
    config(['services.channex.base_url' => 'https://staging.channex.io/api/v1', 'services.channex.api_key' => 'secret-key']);

    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $channelProperty = channelSyncedProperty($property);
    $deluxe = channelRoomType($property, 'Deluxe King');
    $suite = channelRoomType($property, 'Suite');
    $standard = channelRatePlan($property, [$deluxe, $suite], 'Standard');
    $breakfast = channelRatePlan($property, [$deluxe, $suite], 'Breakfast');
    ChannelRoomTypeMapping::factory()->for($channelProperty)->create(['room_type_id' => $deluxe->id]);
    ChannelRatePlanMapping::factory()->for($channelProperty)->create(['rate_plan_id' => $breakfast->id, 'room_type_id' => $deluxe->id]);

    return [$owner, $property, $deluxe, $suite, $standard, $breakfast];
}

test('a room type that is on Channex is not archived at once: its removal is queued first', function () {
    Queue::fake();
    [$owner, $property, $deluxe] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.room-types.archive', [$property->code, $deluxe]))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Deluxe King is on the channels. It is being removed there first and the change applies in a moment.']);

    expect($deluxe->refresh()->archived_at)->toBeNull();
    Queue::assertPushed(ChannelRemovalJob::class, fn (ChannelRemovalJob $job) => $job->kind === ChannelRemovalKind::RoomType && $job->subjectId === $deluxe->id && $job->actorId === $owner->id);
});

test('a room type that is not on Channex is archived as before', function () {
    [$owner, $property, , $suite] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.room-types.archive', [$property->code, $suite]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Suite archived.']);

    expect($suite->refresh()->archived_at)->not->toBeNull();
});

test('a rate plan that is on Channex is not archived at once: its removal is queued first', function () {
    Queue::fake();
    [$owner, $property, , , , $breakfast] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.archive', [$property->code, $breakfast]))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Breakfast is on the channels. It is being removed there first and the change applies in a moment.']);

    expect($breakfast->refresh()->archived_at)->toBeNull();
    Queue::assertPushed(ChannelRemovalJob::class, fn (ChannelRemovalJob $job) => $job->kind === ChannelRemovalKind::RatePlan && $job->subjectId === $breakfast->id);
});

test('a rate plan that is not on Channex is archived as before', function () {
    [$owner, $property, , , $standard] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.archive', [$property->code, $standard]))
        ->assertSessionHasNoErrors();

    expect($standard->refresh()->archived_at)->not->toBeNull();
});

test('a room type is not unlinked from a plan at once while the pair is on Channex: the removal is queued first', function () {
    Queue::fake();
    [$owner, $property, $deluxe, $suite, , $breakfast] = channelGuardScene();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $breakfast]), ['room_type_ids' => [$suite->id]])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Breakfast is on the channels. It is being removed there first and the change applies in a moment.']);

    Queue::assertPushed(ChannelRemovalJob::class, fn (ChannelRemovalJob $job) => $job->kind === ChannelRemovalKind::Unlink && $job->wantedRoomTypeIds === [$suite->id] && $job->removedRoomTypeIds === [$deluxe->id]);

    expect($breakfast->roomTypes()->pluck('room_types.id')->sort()->values()->all())->toBe(collect([$deluxe->id, $suite->id])->sort()->values()->all());
});

test('a pair that is not on Channex can be unlinked', function () {
    [$owner, $property, $deluxe, , , $breakfast] = channelGuardScene();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $breakfast]), ['room_type_ids' => [$deluxe->id]])
        ->assertSessionHasNoErrors();

    expect($breakfast->roomTypes()->count())->toBe(1);
});

test('the actions refuse too when called without the form check', function () {
    [$owner, , $deluxe, , , $breakfast] = channelGuardScene();

    expect(app(ArchiveRoomType::class)($owner, $deluxe))->toBeFalse()
        ->and(app(ArchiveRatePlan::class)($owner, $breakfast))->toBeFalse()
        ->and(app(SyncRatePlanRoomTypes::class)($owner, $breakfast, []))->toBeFalse();
});
