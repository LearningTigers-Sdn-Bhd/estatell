<?php

use App\Models\ApiToken;
use App\Models\Property;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

function pair(string $code, array $extra = []): TestResponse
{
    return test()->postJson('/api/kiosk/v1/pair', ['pairing_code' => $code, 'device_name' => 'KIOSK-01'] + $extra);
}

it('trades a valid pairing code for a token', function () {
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    $kiosk = ApiToken::factory()->for($property)->awaitingPairing('ABCD2345')->create(['name' => 'Lobby']);

    $response = pair('abcd-2345')->assertOk()
        ->assertJsonPath('property.id', $property->id)
        ->assertJsonPath('property.name', 'Palm Resort');

    $token = $response->json('token');
    expect($token)->toStartWith('est_live_');

    $kiosk->refresh();
    expect($kiosk->token_hash)->toBe(hash('sha256', $token))
        ->and($kiosk->pairing_code_hash)->toBeNull()
        ->and($kiosk->device_name)->toBe('KIOSK-01')
        ->and($kiosk->paired_at)->not->toBeNull()
        ->and(json_encode($kiosk->getAttributes()))->not->toContain($token);
    expect(Activity::query()->where('event', 'api-access.kiosk-paired')->exists())->toBeTrue();
});

it('refuses a code that was already used', function () {
    ApiToken::factory()->awaitingPairing('ABCD2345')->create();

    pair('ABCD2345')->assertOk();
    pair('ABCD2345')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses an expired code', function () {
    $kiosk = ApiToken::factory()->awaitingPairing('ABCD2345')->create();

    $this->travel(11)->minutes();

    pair('ABCD2345')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    expect($kiosk->refresh()->token_hash)->toBeNull();
});

it('refuses a wrong, missing or revoked code', function () {
    ApiToken::factory()->awaitingPairing('ABCD2345')->revoked()->create();

    pair('ZZZZ9999')->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    pair('ABCD2345')->assertUnauthorized();
    $this->postJson('/api/kiosk/v1/pair', [])->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses to pair for a suspended property', function () {
    $property = Property::factory()->create(['suspended_at' => now()]);
    ApiToken::factory()->for($property)->awaitingPairing('ABCD2345')->create();

    pair('ABCD2345')->assertUnauthorized();
});

it('rate limits pairing attempts', function () {
    foreach (range(1, 10) as $_) {
        pair('ZZZZ9999')->assertUnauthorized();
    }

    pair('ZZZZ9999')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});
