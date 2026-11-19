<?php

use App\Actions\DoorLocks\FreshAccessToken;
use App\Models\DoorLockConnection;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../Support/DoorLockSetup.php';

it('hashes the password with MD5 and sends the app credentials', function () {
    config(['services.ttlock.client_id' => 'cid', 'services.ttlock.client_secret' => 'csecret']);
    fakeTtlock();

    app(TtlockApi::class)->signIn('me@example.com', 'pw');

    Http::assertSent(fn ($request) => $request['clientId'] === 'cid' && $request['clientSecret'] === 'csecret' && $request['username'] === 'me@example.com' && $request['password'] === md5('pw'));
});

it('explains the errors an owner can fix', function (int $code, string $text) {
    Http::fake(['*' => Http::response(['errcode' => $code, 'errmsg' => 'x'])]);

    expect(fn () => app(TtlockApi::class)->unlock('token', 1))->toThrow(TtlockException::class, $text);
})->with([[-4043, 'Remote unlock'], [-3009, 'memory is full'], [10004, 'expired']]);

it('waits longer for a call through a gateway', function () {
    fakeTtlock();
    Http::fake(['*/v3/lock/unlock' => Http::response(['errcode' => 0])]);

    app(TtlockApi::class)->unlock('token', 1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/lock/unlock') && $request['lockId'] === 1 && is_numeric($request['date']));
});

it('makes a call through a gateway wait for another call to the same lock, then sends it', function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake(['*/v3/lock/unlock' => Http::response(['errcode' => 0])]);
    $other = Cache::lock('ttlock-remote:1', 40);
    $other->get();
    // The other call finishes while this one waits its turn.
    Sleep::whenFakingSleep(fn () => $other->release());

    app(TtlockApi::class)->unlock('token', 1);

    Http::assertSentCount(1);
    Sleep::assertSlept(fn ($duration): bool => true, 1);
});

it('does not hold a call to one lock behind a call to another lock', function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake(['*/v3/lock/unlock' => Http::response(['errcode' => 0])]);
    Cache::lock('ttlock-remote:1', 40)->get();

    app(TtlockApi::class)->unlock('token', 2);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('treats a call that waited too long for its turn as TTLock not answering, and sends nothing', function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake(['*/v3/lock/unlock' => Http::response(['errcode' => 0])]);
    Cache::lock('ttlock-remote:1', 600)->get();

    expect(fn () => app(TtlockApi::class)->unlock('token', 1))->toThrow(TtlockException::class, 'could not be reached');

    Http::assertNothingSent();
});

it('lets the next call to a lock go once a call is done, whether it worked or not', function () {
    Http::fake(['*/v3/lock/unlock' => Http::sequence()->push(['errcode' => -2012, 'errmsg' => 'busy'])->push(['errcode' => 0])]);

    expect(fn () => app(TtlockApi::class)->unlock('token', 1))->toThrow(TtlockException::class);
    app(TtlockApi::class)->unlock('token', 1);

    Http::assertSentCount(2);
});

it('does not take a turn for a call that is not through a gateway', function () {
    Sleep::fake(syncWithCarbon: true);
    Http::fake(['*/v3/lock/list' => Http::response(['list' => [], 'pages' => 1])]);
    Cache::lock('ttlock-remote:1', 40)->get();

    app(TtlockApi::class)->locks('token');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('turns an errcode answer into a TtlockException', function () {
    Http::fake(['*' => Http::response(['errcode' => -2012, 'errmsg' => 'lock offline'])]);

    app(TtlockApi::class)->locks('token');
})->throws(TtlockException::class, 'lock offline');

it('refreshes a token that ends within a day and saves the new ones', function () {
    $connection = DoorLockConnection::factory()->create(['token_expires_at' => now()->addHours(2)]);
    fakeTtlock();

    $token = app(FreshAccessToken::class)($connection);

    expect($token)->toBe('at-new')->and($connection->refresh()->refresh_token)->toBe('rt-new');
    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token');
});

it('keeps a fresh token without calling TTLock', function () {
    $connection = DoorLockConnection::factory()->create(['access_token' => 'still-good', 'token_expires_at' => now()->addDays(20)]);
    fakeTtlock();

    expect(app(FreshAccessToken::class)($connection))->toBe('still-good');
    Http::assertNothingSent();
});
