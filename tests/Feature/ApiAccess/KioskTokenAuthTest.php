<?php

use App\Models\ApiToken;
use App\Models\Property;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const KIOSK_TOKEN = 'est_live_testtokenaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const INTEGRATION_TOKEN = 'est_live_integrationaaaaaaaaaaaaaaaaaaaaaaaaaa';

function kioskGet(string $path = '/api/kiosk/v1/me', ?string $token = KIOSK_TOKEN): TestResponse
{
    return test()->getJson($path, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

it('answers /me with the kiosk and property names', function () {
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    ApiToken::factory()->for($property)->withToken(KIOSK_TOKEN)->create(['name' => 'Lobby kiosk']);

    kioskGet()->assertOk()->assertExactJson(['kiosk_name' => 'Lobby kiosk', 'property_name' => 'Palm Resort']);
});

it('only ever shows the property of the token', function () {
    $other = Property::factory()->create(['name' => 'Other Hotel']);
    ApiToken::factory()->for($other)->create(['name' => 'Other kiosk']);
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    ApiToken::factory()->for($property)->withToken(KIOSK_TOKEN)->create();

    kioskGet('/api/kiosk/v1/me?property_id='.$other->id)->assertOk()->assertJsonPath('property_name', 'Palm Resort');
});

it('stamps last_used_at at most once a minute', function () {
    $kiosk = ApiToken::factory()->withToken(KIOSK_TOKEN)->create();
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
    ApiToken::factory()->withToken(KIOSK_TOKEN)->create();

    kioskGet(token: $token)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
})->with([null, 'est_live_nope', 'not-a-token']);

it('refuses a revoked token', function () {
    ApiToken::factory()->withToken(KIOSK_TOKEN)->revoked()->create();

    kioskGet()->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses an expired token and a token of a suspended property', function () {
    ApiToken::factory()->withToken(KIOSK_TOKEN)->create(['expires_at' => now()->subMinute()]);
    kioskGet()->assertUnauthorized();

    ApiToken::query()->delete();
    ApiToken::factory()->for(Property::factory()->create(['suspended_at' => now()]))->withToken(KIOSK_TOKEN)->create();
    kioskGet()->assertUnauthorized();
});

it('refuses a token without the kiosk scope', function () {
    ApiToken::factory()->withToken(KIOSK_TOKEN)->create(['scopes' => 'v1:read']);

    kioskGet()->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('keeps a kiosk token out of a route that needs another scope, and the reverse', function () {
    Route::middleware(['api', 'api.token:v1:read'])->get('/api/v1/probe', fn () => ['ok' => true]);
    ApiToken::factory()->withToken(KIOSK_TOKEN)->create();
    ApiToken::factory()->withToken(INTEGRATION_TOKEN)->create(['kind' => 'integration', 'scopes' => 'v1:read']);

    kioskGet('/api/v1/probe')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
    kioskGet('/api/v1/probe', INTEGRATION_TOKEN)->assertOk();
    kioskGet(token: INTEGRATION_TOKEN)->assertUnauthorized();
});

it('rate limits a token', function () {
    ApiToken::factory()->withToken(KIOSK_TOKEN)->create();

    foreach (range(1, 120) as $_) {
        kioskGet()->assertOk();
    }

    kioskGet()->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('answers an unexpected error with server_error', function () {
    Route::middleware(['api'])->get('/api/kiosk/v1/boom', fn () => throw new RuntimeException('secret detail'));

    $this->getJson('/api/kiosk/v1/boom')->assertStatus(500)->assertJsonPath('error.code', 'server_error')->assertDontSee('secret detail');
});
