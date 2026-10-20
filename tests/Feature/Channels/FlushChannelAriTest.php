<?php

use App\Actions\Channels\FlushChannelAri;
use App\Actions\Channels\QueueChannelFullSync;
use App\Enums\AriChangeKind;
use App\Enums\ChannelPropertyStatus;
use App\Features\ChannelManager;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelAriChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ChannelStructure.php';

function fakeChannexAri(?array $restrictions = null, ?array $availability = null): void
{
    $ok = fn (string $task): array => ['data' => [['id' => $task, 'type' => 'task']], 'meta' => ['message' => 'Success', 'warnings' => []]];

    Http::fake([
        '*/restrictions' => Http::response($restrictions ?? $ok('task-r'), 200),
        '*/availability' => Http::response($availability ?? $ok('task-a'), 200),
    ]);
}

function waitingChange(array $setup, AriChangeKind $kind, string $from, string $to, bool $withPlan = true): ChannelAriChange
{
    return ChannelAriChange::factory()->create([
        'channel_property_id' => $setup['channelProperty']->id,
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $withPlan ? $setup['ratePlan']->id : null,
        'kind' => $kind,
        'date_from' => $from,
        'date_to' => $to,
    ]);
}

function runFlush(array $setup, bool $full = false): ?int
{
    return app(FlushChannelAri::class)($setup['channelProperty']->fresh(), $full);
}

test('one flush sends one restrictions call and one availability call and marks the changes sent', function () {
    $setup = channelAriSetup(rooms: 3);
    foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $date) {
        channelRate($setup['ratePlan'], $setup['roomType'], $date, $date === '2026-10-22' ? '180.00' : '150.00');
    }
    $rates = waitingChange($setup, AriChangeKind::Rates, '2026-10-20', '2026-10-21');
    waitingChange($setup, AriChangeKind::Restrictions, '2026-10-21', '2026-10-22');
    $availability = waitingChange($setup, AriChangeKind::Availability, '2026-10-20', '2026-10-22', false);
    fakeChannexAri();

    expect(runFlush($setup))->toBeNull();

    expect(channexCalls())->toHaveCount(2);
    $restrictions = channexBodies('POST', '/restrictions')[0]['values'];
    expect($restrictions)->toHaveCount(2)
        ->and($restrictions[0])->toMatchArray(['property_id' => 'prop-1', 'rate_plan_id' => 'plan-1', 'date_from' => '2026-10-20', 'date_to' => '2026-10-21', 'rate' => '150.00', 'stop_sell' => false, 'min_stay_arrival' => 1, 'max_stay' => 0])
        ->and($restrictions[1])->toMatchArray(['date_from' => '2026-10-22', 'date_to' => '2026-10-22', 'rate' => '180.00']);
    expect(channexBodies('POST', '/availability')[0]['values'])->toBe([
        ['property_id' => 'prop-1', 'room_type_id' => 'room-1', 'date_from' => '2026-10-20', 'date_to' => '2026-10-22', 'availability' => 3],
    ]);

    expect($rates->fresh()->sent_at)->not->toBeNull()
        ->and($rates->fresh()->task_id)->toBe('task-r')
        ->and($availability->fresh()->task_id)->toBe('task-a')
        ->and(ChannelAriChange::query()->waiting()->count())->toBe(0)
        ->and($setup['channelProperty']->fresh()->changes_sent_at)->not->toBeNull();
});

test('a flush with nothing waiting calls nothing', function () {
    $setup = channelAriSetup();
    fakeChannexAri();

    expect(runFlush($setup))->toBeNull();
    expect(channexCalls())->toHaveCount(0);
});

test('a rate limit keeps every change, pauses the property and backs off', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-20', '150.00');
    $change = waitingChange($setup, AriChangeKind::Rates, '2026-10-20', '2026-10-20');
    Http::fake(['*/restrictions' => Http::response(['errors' => ['code' => 'http_too_many_requests']], 429)]);

    expect(runFlush($setup))->toBe(60);

    $change->refresh();
    $setup['channelProperty']->refresh();
    expect($change->sent_at)->toBeNull()->and($change->attempts)->toBe(1)
        ->and($setup['channelProperty']->paused_until->toDateTimeString())->toBe(now()->addSeconds(60)->toDateTimeString())
        ->and($setup['channelProperty']->last_error)->toContain('429');

    $this->travelTo(now()->addSeconds(61));
    expect(runFlush($setup))->toBe(120)->and($change->fresh()->attempts)->toBe(2);
});

test('a paused property sends nothing until the pause is over', function () {
    $setup = channelAriSetup();
    waitingChange($setup, AriChangeKind::Availability, '2026-10-20', '2026-10-20', false);
    $setup['channelProperty']->update(['paused_until' => now()->addSeconds(30)]);
    fakeChannexAri();

    expect(runFlush($setup))->toBe(31);
    expect(channexCalls())->toHaveCount(0);

    $this->travelTo(now()->addSeconds(31));
    expect(runFlush($setup))->toBeNull();
    expect(channexCalls())->toHaveCount(1);
});

test('when only the availability call is limited the rate lines are still marked sent', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-20', '150.00');
    $rates = waitingChange($setup, AriChangeKind::Rates, '2026-10-20', '2026-10-20');
    $availability = waitingChange($setup, AriChangeKind::Availability, '2026-10-20', '2026-10-20', false);
    Http::fake([
        '*/restrictions' => Http::response(['data' => [['id' => 'task-r']], 'meta' => ['warnings' => []]], 200),
        '*/availability' => Http::response([], 503),
    ]);

    expect(runFlush($setup))->toBe(60);

    expect($rates->fresh()->sent_at)->not->toBeNull()
        ->and($availability->fresh()->sent_at)->toBeNull()
        ->and($availability->fresh()->attempts)->toBe(1);
});

test('a refusal keeps its error on the changes and a normal flush does not send them again', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-20', '150.00');
    $change = waitingChange($setup, AriChangeKind::Rates, '2026-10-20', '2026-10-20');
    Http::fake(['*/restrictions' => Http::response(['errors' => ['details' => ['rate' => ['is invalid']]]], 422)]);

    expect(runFlush($setup))->toBeNull();

    expect($change->fresh()->sent_at)->toBeNull()
        ->and($change->fresh()->last_error)->toContain('422')
        ->and($setup['channelProperty']->fresh()->last_error)->toContain('is invalid');

    resetChannexFake();
    fakeChannexAri();
    runFlush($setup);
    expect(channexCalls())->toHaveCount(0);
});

test('a warning inside a 200 answer is a refusal', function () {
    $setup = channelAriSetup();
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-20', '150.00');
    $change = waitingChange($setup, AriChangeKind::Rates, '2026-10-20', '2026-10-20');
    fakeChannexAri(['data' => [], 'meta' => ['message' => 'Success', 'warnings' => [['rate' => null, 'warning' => ['rate' => ['must be greater than 0']]]]]]);

    runFlush($setup);

    expect($change->fresh()->sent_at)->toBeNull()->and($change->fresh()->last_error)->toContain('must be greater than 0');
});

test('a change that is wholly in the past is closed without a call', function () {
    $setup = channelAriSetup();
    $change = waitingChange($setup, AriChangeKind::Availability, '2026-10-01', '2026-10-05', false);
    fakeChannexAri();

    runFlush($setup);

    expect($change->fresh()->sent_at)->not->toBeNull();
    expect(channexCalls())->toHaveCount(0);
});

test('a full run sends the next 500 days in two calls and notes the time', function () {
    $setup = channelAriSetup(rooms: 2);
    channelRate($setup['ratePlan'], $setup['roomType'], '2026-10-14', '150.00');
    fakeChannexAri();

    expect(runFlush($setup, full: true))->toBeNull();

    $last = CarbonImmutable::parse('2026-10-14')->addDays(499)->toDateString();
    expect(channexCalls())->toHaveCount(2);
    $restrictions = channexBodies('POST', '/restrictions')[0]['values'];
    expect($restrictions[0])->toMatchArray(['date_from' => '2026-10-14', 'date_to' => '2026-10-14', 'rate' => '150.00', 'stop_sell' => false])
        ->and($restrictions[1])->toMatchArray(['date_from' => '2026-10-15', 'date_to' => $last, 'stop_sell' => true])
        ->and($restrictions[1])->not->toHaveKey('rate');
    expect(channexBodies('POST', '/availability')[0]['values'])->toBe([
        ['property_id' => 'prop-1', 'room_type_id' => 'room-1', 'date_from' => '2026-10-14', 'date_to' => $last, 'availability' => 2],
    ]);
    expect($setup['channelProperty']->fresh()->full_synced_at)->not->toBeNull()
        ->and(ChannelAriChange::query()->waiting()->count())->toBe(0);
});

test('a property that is not synced is not flushed', function () {
    $setup = channelAriSetup();
    waitingChange($setup, AriChangeKind::Availability, '2026-10-20', '2026-10-20', false);
    $setup['channelProperty']->update(['status' => ChannelPropertyStatus::Error]);
    fakeChannexAri();

    expect(runFlush($setup))->toBeNull();
    expect(channexCalls())->toHaveCount(0);
});

test('the job puts a limited flush back with the wait', function () {
    Queue::fake();
    $setup = channelAriSetup();
    waitingChange($setup, AriChangeKind::Availability, '2026-10-20', '2026-10-20', false);
    Http::fake(['*/availability' => Http::response([], 429, ['Retry-After' => '90'])]);

    (new FlushChannelAriJob($setup['channelProperty']->id))->handle(app(FlushChannelAri::class));

    Queue::assertPushed(FlushChannelAriJob::class, fn (FlushChannelAriJob $job): bool => $job->delay->equalTo(now()->addSeconds(90)));
});

test('an admin full refresh is queued once a day and the first one needs a synced property', function () {
    Queue::fake();
    $setup = channelAriSetup();
    $admin = User::factory()->admin()->create();
    $queue = app(QueueChannelFullSync::class);

    expect($queue($setup['channelProperty'], $admin)->ok)->toBeTrue();
    Queue::assertPushed(FlushChannelAriJob::class, fn (FlushChannelAriJob $job): bool => $job->full);

    $setup['channelProperty']->update(['full_synced_at' => now()->subHours(5)]);
    $refused = $queue($setup['channelProperty']->fresh(), $admin);
    expect($refused->ok)->toBeFalse()->and($refused->message)->toContain('24 hours');
    Queue::assertPushed(FlushChannelAriJob::class, 1);

    $setup['channelProperty']->update(['status' => ChannelPropertyStatus::Error]);
    expect($queue($setup['channelProperty']->fresh(), $admin)->ok)->toBeFalse();
});

test('the nightly command queues synced properties with the feature on, a few seconds apart', function () {
    Queue::fake();
    $first = channelAriSetup();
    Feature::for($first['property'])->activate(ChannelManager::class);
    $second = channelAriSetup(externalId: 'prop-2');
    Feature::for($second['property'])->activate(ChannelManager::class);
    $second['channelProperty']->update(['full_synced_at' => now()->subHours(2)]);
    $off = channelAriSetup(externalId: 'prop-3');

    $this->artisan('channels:full-sync')->assertSuccessful();

    Queue::assertPushed(FlushChannelAriJob::class, 1);
    Queue::assertPushed(FlushChannelAriJob::class, fn (FlushChannelAriJob $job): bool => $job->channelPropertyId === $first['channelProperty']->id && $job->full);
});
