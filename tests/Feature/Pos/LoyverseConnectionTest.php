<?php

use App\Actions\Pos\ConnectLoyverse;
use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use App\Features\PosIntegration;
use App\Jobs\SyncLoyverseGuestJob;
use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Models\PosOutletMapping;
use App\Models\User;
use App\Services\Pos\Loyverse\LoyverseClient;
use App\Services\Pos\Loyverse\LoyverseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/LoyverseSetup.php';

function fakeLoyverseConnect(array $webhooks = [[], ['id' => 'wh-9']]): void
{
    $sequence = Http::sequence();

    foreach ($webhooks as $answer) {
        $sequence->push($answer);
    }

    Http::fake([
        'api.loyverse.com/oauth/token' => Http::response(['access_token' => 'at-new', 'token_type' => 'Bearer', 'expires_in' => 43200, 'refresh_token' => 'rt-new']),
        'api.loyverse.com/v1.0/merchant/' => Http::response(['id' => 'merch-9', 'business_name' => 'Kopi Lane']),
        'api.loyverse.com/v1.0/webhooks/' => $sequence,
    ]);
}

it('builds the authorize url with space separated scopes, the redirect uri and the state', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);

    $url = app(LoyverseClient::class)->authorizeUrl('state-1');

    expect($url)->toStartWith('https://api.loyverse.com/oauth/authorize?')
        ->and($url)->toContain('client_id=cid', 'response_type=code', 'state=state-1', 'scope=CUSTOMERS_READ%20CUSTOMERS_WRITE%20RECEIPTS_READ')
        ->and($url)->toContain('redirect_uri='.rawurlencode(route('webhooks.loyverse.oauth-callback')))
        ->and($url)->not->toContain('WEBHOOKS_WRITE');
});

it('connects: saves encrypted tokens, the merchant and the registered receipts webhook', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    fakeLoyverseConnect();

    $refusal = app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'the-code');
    $connection = PosConnection::query()->sole();

    expect($refusal)->toBeNull()
        ->and($connection->status)->toBe(PosConnectionStatus::Connected)
        ->and($connection->provider)->toBe(PosProvider::Loyverse)
        ->and($connection->external_merchant_id)->toBe('merch-9')
        ->and($connection->external_webhook_id)->toBe('wh-9')
        ->and($connection->access_token)->toBe('at-new')
        ->and(DB::table('pos_connections')->value('access_token'))->not->toBe('at-new')
        ->and(Activity::query()->where('event', 'pos.loyverse_connected')->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.loyverse.com/oauth/token' && $request['grant_type'] === 'authorization_code' && $request['code'] === 'the-code' && $request['client_secret'] === 'sec');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.loyverse.com/v1.0/webhooks/' && $request->method() === 'POST' && $request['type'] === 'receipts.update' && $request['url'] === route('webhooks.loyverse'));
});

it('does not register the webhook twice when it already exists', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    fakeLoyverseConnect([[['id' => 'wh-old', 'type' => 'receipts.update', 'url' => route('webhooks.loyverse')]]]);

    app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'the-code');

    expect(PosConnection::query()->sole()->external_webhook_id)->toBe('wh-old');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.loyverse.com/v1.0/webhooks/' && $request->method() === 'POST');
});

it('refuses a Loyverse account that another property already uses', function () {
    $other = loyverseSetup(['external_merchant_id' => 'merch-9']);
    $setup = posSetup();
    fakeLoyverseConnect();

    $refusal = app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'the-code');

    expect($refusal)->toBe(ConnectLoyverse::REFUSAL_MERCHANT_IN_USE)
        ->and(PosConnection::query()->where('property_id', $setup['property']->id)->exists())->toBeFalse()
        ->and($other['connection']->fresh()->external_merchant_id)->toBe('merch-9');
});

it('saves the connection as Error when the webhook cannot be registered', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    Http::fake([
        'api.loyverse.com/oauth/token' => Http::response(['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 43200]),
        'api.loyverse.com/v1.0/merchant/' => Http::response(['id' => 'merch-9']),
        'api.loyverse.com/v1.0/webhooks/' => Http::response(['error' => 'no'], 403),
    ]);

    $refusal = app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'the-code');
    $connection = PosConnection::query()->sole();

    expect($refusal)->toBe(ConnectLoyverse::REFUSAL_WEBHOOK_FAILED)
        ->and($connection->status)->toBe(PosConnectionStatus::Error)
        ->and($connection->last_error)->toContain('webhook');
});

it('refuses a code Loyverse rejects and saves nothing', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    Http::fake(['api.loyverse.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'bad'))->toBe(ConnectLoyverse::REFUSAL_CONNECT_FAILED)
        ->and(PosConnection::query()->count())->toBe(0);
});

it('refreshes an expiring token under a lock before the call, and follows the list cursor', function () {
    $setup = loyverseSetup(['token_expires_at' => now()->addMinutes(2)]);
    Http::fake([
        'api.loyverse.com/oauth/token' => Http::response(['access_token' => 'at-2', 'refresh_token' => 'rt-2', 'expires_in' => 43200]),
        'api.loyverse.com/v1.0/stores*' => Http::sequence()
            ->push(['stores' => [['id' => 's1', 'name' => 'Cafe', 'deleted_at' => null]], 'cursor' => 'next'])
            ->push(['stores' => [['id' => 's2', 'name' => 'Bar', 'deleted_at' => null], ['id' => 's3', 'name' => 'Old', 'deleted_at' => '2026-01-01T00:00:00Z']]]),
    ]);

    $stores = app(LoyverseClient::class)->stores($setup['connection']);

    expect($stores)->toBe([['id' => 's1', 'name' => 'Cafe'], ['id' => 's2', 'name' => 'Bar']])
        ->and($setup['connection']->fresh()->access_token)->toBe('at-2')
        ->and($setup['connection']->fresh()->refresh_token)->toBe('rt-2');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.loyverse.com/oauth/token' && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'rt-1');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1.0/stores') && $request->hasHeader('Authorization', 'Bearer at-2') && ($request['cursor'] ?? null) === 'next');
});

it('does not refresh a token that is still good', function () {
    $setup = loyverseSetup();
    Http::fake(['api.loyverse.com/v1.0/payment_types*' => Http::response(['payment_types' => [['id' => 'pt-room', 'name' => 'Room Charge']]])]);

    expect(app(LoyverseClient::class)->paymentTypes($setup['connection']))->toBe([['id' => 'pt-room', 'name' => 'Room Charge']]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token'));
});

it('marks the connection Error and asks to connect again when the refresh token is refused', function () {
    $setup = loyverseSetup(['token_expires_at' => now()->subMinute()]);
    Http::fake(['api.loyverse.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => app(LoyverseClient::class)->stores($setup['connection']))->toThrow(fn (LoyverseException $exception): bool => $exception->reauthRequired);
    expect($setup['connection']->fresh()->status)->toBe(PosConnectionStatus::Error)
        ->and($setup['connection']->fresh()->last_error)->toContain('Connect Loyverse again');
});

it('throws a rate limit error with the wait Loyverse asks for', function () {
    $setup = loyverseSetup();
    Http::fake(['api.loyverse.com/v1.0/stores*' => Http::response([], 429, ['Retry-After' => '45'])]);

    expect(fn () => app(LoyverseClient::class)->stores($setup['connection']))->toThrow(fn (LoyverseException $exception): bool => $exception->retryAfter === 45);
});

it('finishes the OAuth callback once with a valid state, and refuses a reused or unknown one', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    fakeLoyverseConnect();
    Cache::put('loyverse-oauth:abc', ['property_id' => $setup['property']->id, 'user_id' => $setup['actor']->id], now()->addMinutes(10));

    $this->actingAs($setup['actor'])->get('/webhooks/loyverse/oauth/callback?code=the-code&state=abc')
        ->assertRedirect(route('settings.pos-integrations.index', $setup['property']->code));

    expect(PosConnection::query()->sole()->status)->toBe(PosConnectionStatus::Connected)
        ->and(Cache::has('loyverse-oauth:abc'))->toBeFalse();

    $this->actingAs($setup['actor'])->get('/webhooks/loyverse/oauth/callback?code=the-code&state=abc')->assertRedirect(route('home.redirect'));
    $this->actingAs($setup['actor'])->get('/webhooks/loyverse/oauth/callback?code=the-code&state=nope')->assertRedirect(route('home.redirect'));
    expect(PosConnection::query()->count())->toBe(1);
});

it('sends staff to Loyverse with a single use state that names the property', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();

    $response = $this->actingAs($setup['actor'])->get(route('settings.pos-integrations.loyverse.connect', $setup['property']->code));
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    $response->assertRedirectContains('https://api.loyverse.com/oauth/authorize');
    expect(Cache::get('loyverse-oauth:'.$query['state']))->toBe(['property_id' => $setup['property']->id, 'user_id' => $setup['actor']->id]);
});

it('shows the contact support page, not a 404, while POS integration is off', function () {
    $setup = posSetup();
    Feature::for($setup['property'])->deactivate(PosIntegration::class);
    $url = route('settings.pos-integrations.index', $setup['property']->code);

    $this->actingAs($setup['actor'])->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/pos-integrations/activate'));
    $this->actingAs($setup['actor'])->get(route('settings.pos-integrations.loyverse.connect', $setup['property']->code))->assertNotFound();
});

it('refuses staff without pos.manage and another property', function () {
    $setup = posSetup();
    $nobody = User::factory()->for($setup['property'])->withPermissions(['pos.view'])->create();
    $stranger = posSetup();
    $url = route('settings.pos-integrations.index', $setup['property']->code);

    $this->actingAs($nobody)->get($url)->assertForbidden();
    $this->actingAs($stranger['actor'])->get($url)->assertNotFound();
});

it('shows the connection, the cached catalogue and the saved mappings without any token', function () {
    $setup = loyverseSetup();
    Cache::put('loyverse-catalogue:'.$setup['connection']->id, ['stores' => [['id' => 'store-1', 'name' => 'Cafe']], 'paymentTypes' => [['id' => 'pt-room', 'name' => 'Room Charge']]], now()->addDay());

    $this->actingAs($setup['actor'])->get(route('settings.pos-integrations.index', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/pos-integrations/index')
            ->where('configured', true)
            ->where('connection.status.value', 'connected')
            ->where('connection.merchantId', 'merch-1')
            ->missing('connection.accessToken')
            ->where('catalogue.stores.0.name', 'Cafe')
            ->where('mappings.0.paymentTypeId', 'pt-room')
            ->where('mappings.0.outletId', (string) $setup['outlet']->id));
});

it('refreshes the catalogue into the cache', function () {
    $setup = loyverseSetup();
    Http::fake([
        'api.loyverse.com/v1.0/stores*' => Http::response(['stores' => [['id' => 'store-1', 'name' => 'Cafe']]]),
        'api.loyverse.com/v1.0/payment_types*' => Http::response(['payment_types' => [['id' => 'pt-room', 'name' => 'Room Charge']]]),
    ]);

    $this->actingAs($setup['actor'])->post(route('settings.pos-integrations.loyverse.refresh-catalog', $setup['property']->code))->assertRedirect();

    expect(Cache::get('loyverse-catalogue:'.$setup['connection']->id)['paymentTypes'][0]['id'])->toBe('pt-room');
});

it('says so when Loyverse fails while refreshing the catalogue', function () {
    $setup = loyverseSetup();
    Http::fake(['api.loyverse.com/v1.0/stores*' => Http::response([], 500)]);

    $response = $this->actingAs($setup['actor'])->post(route('settings.pos-integrations.loyverse.refresh-catalog', $setup['property']->code));

    expect($response->getSession()->get('inertia.flash_data.toast')['type'])->toBe('error');
});

it('saves mappings, drops unlisted and incomplete ones, and refuses another property outlet', function () {
    $setup = loyverseSetup();
    $other = posSetup();
    $url = route('settings.pos-integrations.loyverse.mappings.update', $setup['property']->code);

    $this->actingAs($setup['actor'])->put($url, ['mappings' => [
        ['store_id' => 'store-2', 'pos_outlet_id' => $setup['outlet']->id, 'payment_type_id' => 'pt-bill', 'active' => true],
        ['store_id' => 'store-3', 'pos_outlet_id' => null, 'payment_type_id' => null, 'active' => true],
    ]])->assertRedirect();

    expect(PosOutletMapping::query()->pluck('external_store_id')->all())->toBe(['store-2'])
        ->and(Activity::query()->where('event', 'pos.loyverse_mappings_saved')->count())->toBe(1);

    $response = $this->actingAs($setup['actor'])->put($url, ['mappings' => [
        ['store_id' => 'store-9', 'pos_outlet_id' => $other['outlet']->id, 'payment_type_id' => 'pt-x', 'active' => true],
    ]]);

    expect($response->getSession()->get('inertia.flash_data.toast')['type'])->toBe('error')
        ->and(PosOutletMapping::query()->pluck('external_store_id')->all())->toBe(['store-2']);
});

it('queues a sync for every checked-in guest', function () {
    $setup = loyverseSetup();
    loyverseOtherUnit($setup);
    Queue::fake();

    $this->actingAs($setup['actor'])->post(route('settings.pos-integrations.loyverse.sync-guests', $setup['property']->code))->assertRedirect();

    Queue::assertPushed(SyncLoyverseGuestJob::class, 2);
    Queue::assertPushedOn('loyverse', SyncLoyverseGuestJob::class);
});

it('disconnects: removes the webhook, clears the tokens and keeps the history', function () {
    $setup = loyverseSetup();
    Http::fake(['api.loyverse.com/v1.0/webhooks/*' => Http::response(['deleted_object_ids' => ['wh-1']])]);

    $this->actingAs($setup['actor'])->delete(route('settings.pos-integrations.loyverse.disconnect', $setup['property']->code))->assertRedirect();
    $connection = $setup['connection']->fresh();

    expect($connection->status)->toBe(PosConnectionStatus::Disconnected)
        ->and($connection->access_token)->toBeNull()
        ->and($connection->refresh_token)->toBeNull()
        ->and($connection->external_webhook_id)->toBeNull()
        ->and(PosOutletMapping::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'pos.loyverse_disconnected')->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://api.loyverse.com/v1.0/webhooks/wh-1');

    $again = $this->actingAs($setup['actor'])->delete(route('settings.pos-integrations.loyverse.disconnect', $setup['property']->code));
    expect($again->getSession()->get('inertia.flash_data.toast')['type'])->toBe('error');
});

it('refuses the OAuth callback from a different user or with nobody signed in, and burns the state', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    $other = User::factory()->for($setup['property'])->withPermissions(['pos.manage'])->create();
    fakeLoyverseConnect();

    Cache::put('loyverse-oauth:abc', ['property_id' => $setup['property']->id, 'user_id' => $setup['actor']->id], now()->addMinutes(10));
    $this->actingAs($other)->get('/webhooks/loyverse/oauth/callback?code=c&state=abc')->assertRedirect(route('home.redirect'));

    Cache::put('loyverse-oauth:def', ['property_id' => $setup['property']->id, 'user_id' => $setup['actor']->id], now()->addMinutes(10));
    auth()->logout();
    $this->get('/webhooks/loyverse/oauth/callback?code=c&state=def')->assertRedirect(route('home.redirect'));

    expect(PosConnection::query()->count())->toBe(0)
        ->and(Cache::has('loyverse-oauth:abc'))->toBeFalse()
        ->and(Cache::has('loyverse-oauth:def'))->toBeFalse();
    Http::assertNothingSent();
});

it('does not connect from the callback while POS integration is off', function () {
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec']);
    $setup = posSetup();
    Feature::for($setup['property'])->deactivate(PosIntegration::class);
    fakeLoyverseConnect();
    Cache::put('loyverse-oauth:abc', ['property_id' => $setup['property']->id, 'user_id' => $setup['actor']->id], now()->addMinutes(10));

    $response = $this->actingAs($setup['actor'])->get('/webhooks/loyverse/oauth/callback?code=c&state=abc');

    $response->assertRedirect(route('settings.pos-integrations.index', $setup['property']->code));
    expect($response->getSession()->get('inertia.flash_data.toast')['type'])->toBe('error')
        ->and(PosConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not spend the refresh token twice when another request already refreshed while this one waited', function () {
    $setup = loyverseSetup();
    $stale = PosConnection::query()->findOrFail($setup['connection']->id);
    DB::table('pos_connections')->where('id', $stale->id)->update(['access_token' => encrypt('at-2', false), 'refresh_token' => encrypt('rt-2', false), 'token_expires_at' => now()->addHours(11)]);
    Http::fake([
        'api.loyverse.com/oauth/token' => Http::response(['access_token' => 'at-3', 'refresh_token' => 'rt-3', 'expires_in' => 43200]),
        'api.loyverse.com/v1.0/payment_types*' => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer at-2')
            ? Http::response(['payment_types' => [['id' => 'pt-room', 'name' => 'Room Charge']]])
            : Http::response([], 401),
    ]);

    $types = app(LoyverseClient::class)->paymentTypes($stale);

    expect($types)->toBe([['id' => 'pt-room', 'name' => 'Room Charge']])
        ->and($stale->fresh()->refresh_token)->toBe('rt-2');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token'));
});

it('drops the old account stores, mappings, guests and catalogue when a different Loyverse account is connected', function () {
    $setup = loyverseSetup();
    loyverseLink($setup);
    Cache::put('loyverse-catalogue:'.$setup['connection']->id, ['stores' => [], 'paymentTypes' => []], now()->addDay());
    fakeLoyverseConnect();

    app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'the-code');

    expect(PosConnection::query()->sole()->external_merchant_id)->toBe('merch-9')
        ->and(PosOutletMapping::query()->count())->toBe(0)
        ->and(PosGuestLink::query()->count())->toBe(0)
        ->and(Cache::has('loyverse-catalogue:'.$setup['connection']->id))->toBeFalse();
});

it('keeps the mappings and guests when the same Loyverse account reconnects', function () {
    $setup = loyverseSetup(['external_merchant_id' => 'merch-9']);
    loyverseLink($setup);
    Cache::put('loyverse-catalogue:'.$setup['connection']->id, ['stores' => [], 'paymentTypes' => []], now()->addDay());
    fakeLoyverseConnect();

    app(ConnectLoyverse::class)($setup['actor'], $setup['property'], 'the-code');

    expect(PosOutletMapping::query()->count())->toBe(1)
        ->and(PosGuestLink::query()->count())->toBe(1)
        ->and(Cache::has('loyverse-catalogue:'.$setup['connection']->id))->toBeTrue();
});
