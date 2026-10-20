<?php

use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rooms\ArchiveRoomType;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Room;
use App\Models\User;

require_once __DIR__.'/../Support/ChannelStructure.php';

/** An owner whose property has two room types and two plans, with one pair synced to Channex. */
function channelGuardScene(): array
{
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

test('a room type that is on Channex cannot be archived', function () {
    [$owner, $property, $deluxe] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.room-types.archive', [$property->code, $deluxe]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Deluxe King is on the channel manager. Ask the estatell team to remove it there first.']);

    expect($deluxe->refresh()->archived_at)->toBeNull();
});

test('a room type that is not on Channex is archived as before', function () {
    [$owner, $property, , $suite] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.room-types.archive', [$property->code, $suite]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Suite archived.']);

    expect($suite->refresh()->archived_at)->not->toBeNull();
});

test('a rate plan that is on Channex cannot be archived', function () {
    [$owner, $property, , , , $breakfast] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.archive', [$property->code, $breakfast]))
        ->assertSessionHasErrors(['rate_plan' => 'Breakfast is on the channel manager. Ask the estatell team to remove it there first.']);

    expect($breakfast->refresh()->archived_at)->toBeNull();
});

test('a rate plan that is not on Channex is archived as before', function () {
    [$owner, $property, , , $standard] = channelGuardScene();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.archive', [$property->code, $standard]))
        ->assertSessionHasNoErrors();

    expect($standard->refresh()->archived_at)->not->toBeNull();
});

test('a room type cannot be unlinked from a plan while the pair is on Channex', function () {
    [$owner, $property, $deluxe, $suite, , $breakfast] = channelGuardScene();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $breakfast]), ['room_type_ids' => [$suite->id]])
        ->assertSessionHasErrors(['room_type_ids' => 'Breakfast is on the channel manager. Ask the estatell team to remove it there first.']);

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
