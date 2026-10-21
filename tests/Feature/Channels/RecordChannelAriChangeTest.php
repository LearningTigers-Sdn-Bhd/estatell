<?php

use App\Enums\AriChangeKind;
use App\Enums\ChannelPropertyStatus;
use App\Enums\RateDerivation;
use App\Events\InventoryChanged;
use App\Events\RatesChanged;
use App\Events\ReservationChanged;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelAriChange;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/ChannelStructure.php';

test('a rate change records one line for each pair of the plan and starts one delayed flush', function () {
    Queue::fake();
    $setup = channelAriSetup();

    RatesChanged::dispatch($setup['property']->id, '2026-10-20', '2026-10-25', $setup['roomType']->id, $setup['ratePlan']->id);
    RatesChanged::dispatch($setup['property']->id, '2026-10-26', '2026-10-30', null, $setup['ratePlan']->id);

    $change = ChannelAriChange::query()->sole();
    expect($change->kind)->toBe(AriChangeKind::Rates)
        ->and($change->room_type_id)->toBe($setup['roomType']->id)
        ->and($change->rate_plan_id)->toBe($setup['ratePlan']->id)
        ->and($change->date_from->toDateString())->toBe('2026-10-20')
        ->and($change->date_to->toDateString())->toBe('2026-10-30')
        ->and($change->sent_at)->toBeNull();

    Queue::assertPushed(FlushChannelAriJob::class, fn (FlushChannelAriJob $job): bool => $job->channelPropertyId === $setup['channelProperty']->id && ! $job->full && $job->delay->gte(now()->addSeconds(10)) && $job->delay->lte(now()->addSeconds(30)));
});

test('a derived plan follows a change to its parent', function () {
    Queue::fake();
    $setup = channelAriSetup();
    $derived = channelRatePlan($setup['property'], [$setup['roomType']], 'Member', attributes: ['parent_rate_plan_id' => $setup['ratePlan']->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10.00']);
    $setup['channelProperty']->ratePlanMappings()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $derived->id, 'external_rate_plan_id' => 'plan-2']);

    RatesChanged::dispatch($setup['property']->id, '2026-10-20', '2026-10-21', null, $setup['ratePlan']->id);

    expect(ChannelAriChange::query()->pluck('rate_plan_id')->all())->toEqualCanonicalizing([$setup['ratePlan']->id, $derived->id]);
});

test('inventory and booking changes record availability for the room type', function () {
    Queue::fake();
    $setup = channelAriSetup();
    $other = channelRoomType($setup['property'], 'Garden');

    InventoryChanged::dispatch($setup['property']->id, '2026-10-20', '2026-10-22', $setup['roomType']->id);
    ReservationChanged::dispatch($setup['property']->id, '2026-10-23', '2026-10-24', $setup['roomType']->id);
    ReservationChanged::dispatch($setup['property']->id, '2026-10-23', '2026-10-24', $other->id);

    $change = ChannelAriChange::query()->sole();
    expect($change->kind)->toBe(AriChangeKind::Availability)
        ->and($change->rate_plan_id)->toBeNull()
        ->and($change->date_from->toDateString())->toBe('2026-10-20')
        ->and($change->date_to->toDateString())->toBe('2026-10-24');
});

test('a range apart from a waiting line gets its own line, and dates outside the next 500 days are dropped', function () {
    Queue::fake();
    $setup = channelAriSetup();

    InventoryChanged::dispatch($setup['property']->id, '2026-10-20', '2026-10-21', $setup['roomType']->id);
    InventoryChanged::dispatch($setup['property']->id, '2026-11-20', '2026-11-21', $setup['roomType']->id);
    InventoryChanged::dispatch($setup['property']->id, '2026-10-01', '2026-10-10', $setup['roomType']->id);
    InventoryChanged::dispatch($setup['property']->id, '2029-01-01', '2029-01-05', $setup['roomType']->id);

    expect(ChannelAriChange::query()->count())->toBe(2);
});

test('a past date is cut off the range', function () {
    Queue::fake();
    $setup = channelAriSetup();

    InventoryChanged::dispatch($setup['property']->id, '2026-10-10', '2026-10-15', $setup['roomType']->id);

    expect(ChannelAriChange::query()->sole()->date_from->toDateString())->toBe('2026-10-14');
});

test('a property that is not synced records nothing', function () {
    Queue::fake();
    $setup = channelAriSetup();
    $setup['channelProperty']->update(['status' => ChannelPropertyStatus::Error]);

    InventoryChanged::dispatch($setup['property']->id, '2026-10-20', '2026-10-21', $setup['roomType']->id);
    InventoryChanged::dispatch(999999, '2026-10-20', '2026-10-21', $setup['roomType']->id);

    expect(ChannelAriChange::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('a paused property keeps the change but starts no flush', function () {
    Queue::fake();
    $setup = channelAriSetup();
    $setup['channelProperty']->update(['status' => ChannelPropertyStatus::Paused]);

    InventoryChanged::dispatch($setup['property']->id, '2026-10-20', '2026-10-21', $setup['roomType']->id);

    expect(ChannelAriChange::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});
