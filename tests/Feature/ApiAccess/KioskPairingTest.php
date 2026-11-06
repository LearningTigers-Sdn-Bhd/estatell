<?php

use App\Models\Kiosk;
use App\Models\Property;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

function pair(string $code, array $extra = []): TestResponse
{
    return test()->postJson('/api/kiosk/v1/pair', ['pairing_code' => $code, 'device_name' => 'KIOSK-01'] + $extra);
}

it('trades a valid pairing code for a token', function () {
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    $kiosk = Kiosk::factory()->for($property)->awaitingPairing('ABCD2345')->create(['name' => 'Lobby']);

    $response = pair('abcd-2345')->assertOk()
        ->assertJsonPath('property.id', $property->id)
        ->assertJsonPath('property.name', 'Palm Resort');

    $token = $response->json('token');
    expect($token)->toMatch('/^\d+\|est_live_[A-Za-z0-9]{40}[0-9a-f]{8}$/');

    $kiosk->refresh();
    $sanctumToken = $kiosk->tokens()->sole();
    expect($sanctumToken->token)->toBe(hash('sha256', explode('|', $token)[1]))
        ->and($sanctumToken->abilities)->toBe(['kiosk:*'])
        ->and($kiosk->pairing_code_hash)->toBeNull()
        ->and($kiosk->device_name)->toBe('KIOSK-01')
        ->and($kiosk->paired_at)->not->toBeNull()
        ->and(json_encode($sanctumToken->getAttributes()))->not->toContain(explode('|', $token)[1]);
    expect(Activity::query()->where('event', 'api-access.kiosk-paired')->exists())->toBeTrue();
});

it('gives a token that works, on the kiosk API only', function () {
    Route::middleware(['api', 'api.token:v1:read'])->get('/api/v1/probe', fn () => ['ok' => true]);
    Kiosk::factory()->awaitingPairing('ABCD2345')->create(['name' => 'Lobby']);

    $headers = ['Authorization' => 'Bearer '.pair('ABCD2345')->json('token')];

    $this->getJson('/api/kiosk/v1/me', $headers)->assertOk()->assertJsonPath('kiosk_name', 'Lobby');
    $this->getJson('/api/v1/probe', $headers)->assertUnauthorized();
});

it('refuses a code that was already used', function () {
    Kiosk::factory()->awaitingPairing('ABCD2345')->create();

    pair('ABCD2345')->assertOk();
    pair('ABCD2345')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses an expired code', function () {
    $kiosk = Kiosk::factory()->awaitingPairing('ABCD2345')->create();

    $this->travel(11)->minutes();

    pair('ABCD2345')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    expect($kiosk->tokens()->count())->toBe(0);
});

it('refuses a wrong, missing or revoked code', function () {
    Kiosk::factory()->awaitingPairing('ABCD2345')->revoked()->create();

    pair('ZZZZ9999')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    pair('ABCD2345')->assertUnauthorized();
    $this->postJson('/api/kiosk/v1/pair', [])->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses to pair for a suspended property', function () {
    $property = Property::factory()->create(['suspended_at' => now()]);
    Kiosk::factory()->for($property)->awaitingPairing('ABCD2345')->create();

    pair('ABCD2345')->assertUnauthorized();
});

it('rate limits pairing attempts', function () {
    foreach (range(1, 10) as $_) {
        pair('ZZZZ9999')->assertUnauthorized();
    }

    pair('ZZZZ9999')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});
