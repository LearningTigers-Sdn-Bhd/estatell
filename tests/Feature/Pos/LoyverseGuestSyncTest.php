<?php

use App\Actions\Pos\RemoveLoyverseGuest;
use App\Actions\Pos\SyncLoyverseGuest;
use App\Enums\PosConnectionStatus;
use App\Enums\UnitStatus;
use App\Events\StayCheckedIn;
use App\Events\StayCheckedOut;
use App\Events\StayRoomChanged;
use App\Features\PosIntegration;
use App\Jobs\RemoveLoyverseGuestJob;
use App\Jobs\SyncLoyverseGuestJob;
use App\Models\PosGuestLink;
use App\Models\Room;
use App\Models\UnitNight;
use App\Services\Pos\Loyverse\LoyverseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/LoyverseSetup.php';

it('queues a customer sync on check-in and on a room change, and a removal on check-out', function () {
    $setup = loyverseSetup();
    Queue::fake();

    StayCheckedIn::dispatch($setup['property']->id, $setup['unit']->id);
    StayRoomChanged::dispatch($setup['property']->id, $setup['unit']->id);
    StayCheckedOut::dispatch($setup['property']->id, $setup['unit']->id);

    Queue::assertPushed(SyncLoyverseGuestJob::class, 2);
    Queue::assertPushed(RemoveLoyverseGuestJob::class, 1);
    Queue::assertPushedOn('loyverse', RemoveLoyverseGuestJob::class);
});

it('queues nothing while the feature is off, with no connection, or once disconnected', function () {
    $setup = loyverseSetup();
    Queue::fake();

    Feature::for($setup['property'])->deactivate(PosIntegration::class);
    StayCheckedIn::dispatch($setup['property']->id, $setup['unit']->id);

    Feature::for($setup['property'])->activate(PosIntegration::class);
    $setup['connection']->forceFill(['status' => PosConnectionStatus::Disconnected])->save();
    StayCheckedIn::dispatch($setup['property']->id, $setup['unit']->id);

    $stranger = posSetup();
    StayCheckedIn::dispatch($stranger['property']->id, $stranger['unit']->id);

    Queue::assertNotPushed(SyncLoyverseGuestJob::class);
    Queue::assertNotPushed(RemoveLoyverseGuestJob::class);
});

it('creates one customer named after the room and guest, then sends nothing while the label is unchanged', function () {
    $setup = loyverseSetup();
    loyverseRoom($setup, '301');
    Http::fake(['api.loyverse.com/v1.0/customers' => Http::response(['id' => 'cust-1'])]);
    $guest = $setup['unit']->reservation->booker->full_name;
    $reference = $setup['unit']->reservation->reference;

    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);
    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    $link = PosGuestLink::query()->sole();
    expect($link->external_customer_id)->toBe('cust-1')
        ->and($link->synced_label)->toBe(mb_substr('Room 301 - '.$guest, 0, 64))
        ->and($link->reservation_unit_id)->toBe($setup['unit']->id);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['name'] === mb_substr('Room 301 - '.$guest, 0, 64)
        && $request['note'] === 'Estatell '.$reference
        && ! isset($request['id']));
});

it('renames the same customer after a room move', function () {
    $setup = loyverseSetup();
    $room = loyverseRoom($setup, '301');
    loyverseLink($setup, 'cust-1', 'Room 301 - '.$setup['unit']->reservation->booker->full_name);
    $moved = Room::factory()->create(['property_id' => $setup['property']->id, 'number' => '302']);
    UnitNight::query()->where('reservation_unit_id', $setup['unit']->id)->update(['room_id' => $moved->id]);
    Http::fake(['api.loyverse.com/v1.0/customers' => Http::response(['id' => 'cust-1'])]);

    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect($room->number)->toBe('301')
        ->and(PosGuestLink::query()->sole()->synced_label)->toContain('Room 302');
    Http::assertSent(fn (Request $request): bool => $request['id'] === 'cust-1' && str_contains($request['name'], 'Room 302'));
});

it('leaves a unit with no room for the room change event', function () {
    $setup = loyverseSetup();
    UnitNight::query()->where('reservation_unit_id', $setup['unit']->id)->delete();
    Http::fake();

    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('deletes the customer and the link on check-out', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    Http::fake(['api.loyverse.com/v1.0/customers/cust-1' => Http::response(['deleted_object_ids' => ['cust-1']])]);

    app(RemoveLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://api.loyverse.com/v1.0/customers/cust-1');
});

it('renames the customer when Loyverse refuses the delete, then drops the link', function () {
    $setup = loyverseSetup();
    loyverseLink($setup, 'cust-1', 'Room 301 - Guest');
    Http::fake([
        'api.loyverse.com/v1.0/customers/cust-1' => Http::response(['error' => 'no'], 403),
        'api.loyverse.com/v1.0/customers' => Http::response(['id' => 'cust-1']),
    ]);

    app(RemoveLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['id'] === 'cust-1' && $request['name'] === '[Checked Out] Room 301 - Guest');
});

it('removes the customer of a unit that is no longer checked in, and does nothing for an unlinked unit', function () {
    $setup = loyverseSetup();
    loyverseRoom($setup);
    loyverseLink($setup);
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    Http::fake(['api.loyverse.com/v1.0/customers/cust-1' => Http::response(['deleted_object_ids' => ['cust-1']])]);

    app(SyncLoyverseGuest::class)($setup['connection'], $setup['unit']->id);
    app(RemoveLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->count())->toBe(0);
    Http::assertSentCount(1);
});

it('releases the job for the wait Loyverse asks for when it is rate limited', function () {
    $setup = loyverseSetup();
    loyverseRoom($setup);
    Http::fake(['api.loyverse.com/v1.0/customers' => Http::response([], 429, ['Retry-After' => '45'])]);

    $job = (new SyncLoyverseGuestJob($setup['connection']->id, $setup['unit']->id))->withFakeQueueInteractions();
    $job->handle(app(SyncLoyverseGuest::class));

    $job->assertReleased(delay: 45);
    expect(PosGuestLink::query()->count())->toBe(0);
});

it('gives up quietly and marks the connection when the merchant revoked access', function () {
    $setup = loyverseSetup(['token_expires_at' => now()->subMinute()]);
    loyverseRoom($setup);
    Http::fake(['api.loyverse.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $job = (new SyncLoyverseGuestJob($setup['connection']->id, $setup['unit']->id))->withFakeQueueInteractions();
    $job->handle(app(SyncLoyverseGuest::class));

    $job->assertNotReleased()->assertNotFailed();
    expect($setup['connection']->fresh()->status)->toBe(PosConnectionStatus::Error);
});

it('skips the job when the connection is no longer connected', function () {
    $setup = loyverseSetup();
    $setup['connection']->forceFill(['status' => PosConnectionStatus::Disconnected])->save();
    Http::fake();

    (new SyncLoyverseGuestJob($setup['connection']->id, $setup['unit']->id))->handle(app(SyncLoyverseGuest::class));

    Http::assertNothingSent();
});

it('retries a Loyverse server error on delete instead of renaming the customer', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    Http::fake(['api.loyverse.com/v1.0/customers/cust-1' => Http::response([], 503)]);

    expect(fn () => app(RemoveLoyverseGuest::class)($setup['connection'], $setup['unit']->id))->toThrow(LoyverseException::class);

    expect(PosGuestLink::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('treats a missing customer as already deleted', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    Http::fake(['api.loyverse.com/v1.0/customers/cust-1' => Http::response([], 404)]);

    app(RemoveLoyverseGuest::class)($setup['connection'], $setup['unit']->id);

    expect(PosGuestLink::query()->count())->toBe(0);
    Http::assertSentCount(1);
});
