<?php

use App\Enums\ChannelAlertEvent;
use App\Features\ChannelManager;
use App\Jobs\PullChannelRevisionsJob;
use App\Models\ChannelAlert;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ChannelBooking.php';

/** Post a webhook signed the way Channex signs it. */
function postChannexWebhook($test, array $body, ?string $secret = 'whsec', ?int $timestamp = null)
{
    $raw = json_encode($body);
    $timestamp ??= now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $secret ?? 'wrong');

    return $test->call('POST', route('webhooks.channex'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HMAC_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_HMAC_SIGNATURE_256' => $signature,
    ], $raw);
}

beforeEach(function () {
    config(['services.channex.webhook_secret' => 'whsec']);
});

test('a booking event pulls the feed for that property', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    Queue::fake();

    postChannexWebhook($this, ['event' => 'booking', 'property_id' => 'prop-1', 'payload' => ['booking_id' => 'b', 'revision_id' => 'r']])->assertOk();

    Queue::assertPushed(PullChannelRevisionsJob::class, fn (PullChannelRevisionsJob $job) => $job->channelPropertyId === $setup['channelProperty']->id);
});

test('an alert event is kept once while it is open', function (string $event, ChannelAlertEvent $expected) {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    $body = ['event' => $event, 'property_id' => 'prop-1', 'payload' => ['booking_id' => 'b-1', 'booking_revision_id' => 'rev-1', 'channel_name' => 'Agoda']];

    postChannexWebhook($this, $body)->assertOk();
    postChannexWebhook($this, $body)->assertOk();

    $alert = ChannelAlert::query()->sole();
    expect($alert->event)->toBe($expected)
        ->and($alert->channel_property_id)->toBe($setup['channelProperty']->id)
        ->and($alert->revision_id)->toBe('rev-1')
        ->and($alert->external_booking_id)->toBe('b-1')
        ->and($alert->resolved_at)->toBeNull();
})->with([
    ['non_acked_booking', ChannelAlertEvent::NonAckedBooking],
    ['booking_unmapped_room', ChannelAlertEvent::BookingUnmappedRoom],
    ['booking_unmapped_rate', ChannelAlertEvent::BookingUnmappedRate],
    ['sync_error', ChannelAlertEvent::SyncError],
    ['rate_error', ChannelAlertEvent::RateError],
]);

test('a wrong signature, an old timestamp or no secret is refused', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    Queue::fake();
    $body = ['event' => 'booking', 'property_id' => 'prop-1', 'payload' => []];

    postChannexWebhook($this, $body, secret: null)->assertUnauthorized();
    postChannexWebhook($this, $body, timestamp: now()->subMinutes(10)->timestamp)->assertUnauthorized();
    $this->postJson(route('webhooks.channex'), $body)->assertUnauthorized();
    config(['services.channex.webhook_secret' => null]);
    postChannexWebhook($this, $body)->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('an unknown property or a property with the channel manager off is answered 200 and dropped', function () {
    $setup = channelBookingSetup();
    Queue::fake();

    postChannexWebhook($this, ['event' => 'booking', 'property_id' => 'prop-1', 'payload' => []])->assertOk();
    postChannexWebhook($this, ['event' => 'booking', 'property_id' => 'nope', 'payload' => []])->assertOk();
    postChannexWebhook($this, ['event' => 'sync_error', 'property_id' => 'prop-1', 'payload' => []])->assertOk();

    Queue::assertNothingPushed();
    expect(ChannelAlert::query()->count())->toBe(0);
});

test('an event kind we do not use is answered 200 and dropped', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    Queue::fake();

    postChannexWebhook($this, ['event' => 'message', 'property_id' => 'prop-1', 'payload' => []])->assertOk();

    Queue::assertNothingPushed();
    expect(ChannelAlert::query()->count())->toBe(0);
});
