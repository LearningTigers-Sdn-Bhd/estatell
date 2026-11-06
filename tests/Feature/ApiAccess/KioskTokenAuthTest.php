<?php

use App\Models\Kiosk;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

const KIOSK_TOKEN = 'est_live_testtokenaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const INTEGRATION_TOKEN = 'est_live_integrationaaaaaaaaaaaaaaaaaaaaaaaaaa';

function kioskGet(string $path = '/api/kiosk/v1/me', ?string $token = KIOSK_TOKEN): TestResponse
{
    return test()->getJson($path, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

it('answers /me with the kiosk and property names', function () {
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    Kiosk::factory()->for($property)->withToken(KIOSK_TOKEN)->create(['name' => 'Lobby kiosk']);

    kioskGet()->assertOk()->assertExactJson(['kiosk_name' => 'Lobby kiosk', 'property_name' => 'Palm Resort']);
});

it('only ever shows the property of the token', function () {
    $other = Property::factory()->create(['name' => 'Other Hotel']);
    Kiosk::factory()->for($other)->create(['name' => 'Other kiosk']);
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    Kiosk::factory()->for($property)->withToken(KIOSK_TOKEN)->create();

    kioskGet('/api/kiosk/v1/me?property_id='.$other->id)->assertOk()->assertJsonPath('property_name', 'Palm Resort');
});

it('stamps last_used_at at most once a minute', function () {
    $kiosk = Kiosk::factory()->withToken(KIOSK_TOKEN)->create();
    $first = now();

    kioskGet()->assertOk();
    expect($kiosk->refresh()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(30)->seconds();
    kioskGet()->assertOk();
    expect($kiosk->refresh()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    kioskGet()->assertOk();
    expect($kiosk->refresh()->last_used_at->equalTo(now()))->toBeTrue();
});

it('refuses a missing, unknown or malformed token', function (?string $token) {
    Kiosk::factory()->withToken(KIOSK_TOKEN)->create();

    kioskGet(token: $token)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
})->with([null, 'est_live_nope', 'not-a-token', '5|nope', 'abc|nope']);

it('refuses a revoked token', function () {
    Kiosk::factory()->withToken(KIOSK_TOKEN)->revoked()->create();

    kioskGet()->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses an expired token and a token of a suspended property', function () {
    Kiosk::factory()->withToken(KIOSK_TOKEN)->create()->tokens()->update(['expires_at' => now()->subMinute()]);
    kioskGet()->assertUnauthorized();

    PersonalAccessToken::query()->delete();
    Kiosk::factory()->for(Property::factory()->create(['suspended_at' => now()]))->withToken(KIOSK_TOKEN)->create();
    kioskGet()->assertUnauthorized();
});

it('answers the request of each token, not the one before it', function () {
    Kiosk::factory()->withToken(KIOSK_TOKEN)->create(['name' => 'First']);
    Kiosk::factory()->withToken(INTEGRATION_TOKEN)->create(['name' => 'Second']);

    kioskGet()->assertJsonPath('kiosk_name', 'First');
    kioskGet(token: INTEGRATION_TOKEN)->assertJsonPath('kiosk_name', 'Second');
    kioskGet(token: null)->assertUnauthorized();
});

it('never treats a kiosk as a signed-in staff user', function () {
    Route::middleware(['api', 'api.token:kiosk:*'])->get('/api/kiosk/v1/who', fn (Request $request) => ['staff' => $request->user()?->getKey()]);
    Kiosk::factory()->withToken(KIOSK_TOKEN)->create();

    kioskGet('/api/kiosk/v1/who')->assertOk()->assertExactJson(['staff' => null]);
});

it('does not let a staff session stand in for a token', function () {
    $this->actingAs(User::factory()->owner()->create());

    kioskGet(token: null)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses a token without the kiosk scope', function () {
    Kiosk::factory()->withToken(KIOSK_TOKEN, ['v1:read'])->create();

    kioskGet()->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('keeps a kiosk token out of a route that needs another scope, and the reverse', function () {
    Route::middleware(['api', 'api.token:v1:read'])->get('/api/v1/probe', fn () => ['ok' => true]);
    Kiosk::factory()->withToken(KIOSK_TOKEN)->create();
    Kiosk::factory()->withToken(INTEGRATION_TOKEN, ['v1:read'])->create();

    kioskGet('/api/v1/probe')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
    kioskGet('/api/v1/probe', INTEGRATION_TOKEN)->assertOk();
    kioskGet(token: INTEGRATION_TOKEN)->assertUnauthorized();
});

it('rate limits a token', function () {
    Kiosk::factory()->withToken(KIOSK_TOKEN)->create();

    foreach (range(1, 120) as $_) {
        kioskGet()->assertOk();
    }

    kioskGet()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

    Kiosk::factory()->withToken(INTEGRATION_TOKEN)->create();
    kioskGet(token: INTEGRATION_TOKEN)->assertOk();
});

it('answers an unexpected error with server_error', function () {
    Route::middleware(['api'])->get('/api/kiosk/v1/boom', fn () => throw new RuntimeException('secret detail'));

    $this->getJson('/api/kiosk/v1/boom')->assertStatus(500)->assertJsonPath('error.code', 'server_error')->assertDontSee('secret detail');
});
