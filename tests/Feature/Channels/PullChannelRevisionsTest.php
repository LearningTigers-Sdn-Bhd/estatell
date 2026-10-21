<?php

use App\Actions\Channels\PullChannelRevisions;
use App\Features\ChannelManager;
use App\Models\ChannelBookingRevision;
use App\Models\Reservation;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ChannelBooking.php';

function fakeChannexFeed(array $revisions, int $feedStatus = 200): void
{
    Http::fake([
        '*/booking_revisions/feed*' => Http::response(
            $feedStatus === 200 ? ['data' => array_map(fn (array $revision): array => ['type' => 'booking_revision', 'id' => $revision['id'], 'attributes' => $revision], $revisions)] : [],
            $feedStatus,
        ),
        '*/booking_revisions/*/ack' => Http::response(['meta' => ['message' => 'Success']], 200),
    ]);
}

test('the feed pull saves each revision oldest first and acknowledges it', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    $new = channelRevisionPayload(['id' => 'rev-1']);
    $modified = channelRevisionPayload(['id' => 'rev-2', 'status' => 'modified', 'rooms' => [channelRevisionRoom(['checkin_date' => '2026-10-21', 'checkout_date' => '2026-10-23', 'amount' => '210.00'])]]);
    fakeChannexFeed([$new, $modified]);

    expect(app(PullChannelRevisions::class)($setup['channelProperty']))->toBeNull();

    expect(Reservation::query()->count())->toBe(1)
        ->and(Reservation::query()->sole()->units()->sole()->arrival_date->toDateString())->toBe('2026-10-21')
        ->and(ChannelBookingRevision::query()->whereNotNull('acknowledged_at')->count())->toBe(2)
        ->and(channexAcks())->toHaveCount(2);
    $feed = collect(channexCalls())->first(fn ($request) => str_contains($request->url(), '/booking_revisions/feed'));
    expect($feed->url())->toContain('filter%5Bproperty_id%5D=prop-1');
});

test('a revision already saved is only acknowledged again, never booked twice', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    $revision = channelRevisionPayload(['id' => 'rev-1']);
    fakeChannexFeed([$revision]);
    app(PullChannelRevisions::class)($setup['channelProperty']);
    ChannelBookingRevision::query()->update(['acknowledged_at' => null]);

    app(PullChannelRevisions::class)($setup['channelProperty']);

    expect(Reservation::query()->count())->toBe(1)
        ->and(ChannelBookingRevision::query()->count())->toBe(1)
        ->and(ChannelBookingRevision::query()->sole()->acknowledged_at)->not->toBeNull();
});

test('a rate limit stops the pull and returns the wait', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    fakeChannexFeed([], 429);

    expect(app(PullChannelRevisions::class)($setup['channelProperty']))->toBe(60);
});

test('a property with the channel manager off is not pulled', function () {
    $setup = channelBookingSetup();
    fakeChannexFeed([channelRevisionPayload()]);

    expect(app(PullChannelRevisions::class)($setup['channelProperty']))->toBeNull()
        ->and(channexCalls())->toBe([])
        ->and(Reservation::query()->count())->toBe(0);
});

test('a refused revision does not stop the next one', function () {
    $setup = channelBookingSetup();
    Feature::for($setup['property'])->activate(ChannelManager::class);
    $bad = channelRevisionPayload(['id' => 'rev-1', 'booking_id' => 'b-bad', 'rooms' => [channelRevisionRoom(['room_type_id' => null])]]);
    $good = channelRevisionPayload(['id' => 'rev-2', 'booking_id' => 'b-good']);
    fakeChannexFeed([$bad, $good]);

    app(PullChannelRevisions::class)($setup['channelProperty']);

    expect(ChannelBookingRevision::query()->where('revision_id', 'rev-1')->sole()->error)->not->toBeNull()
        ->and(ChannelBookingRevision::query()->where('revision_id', 'rev-2')->sole()->acknowledged_at)->not->toBeNull()
        ->and(Reservation::query()->count())->toBe(1);
});
