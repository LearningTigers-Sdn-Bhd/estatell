<?php

use App\Actions\DoorLocks\CreateKeyportPairingCode;
use App\Models\KeyportDevice;
use App\Models\KeyportPairingCode;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Support/KeyportSetup.php';

function keyportPair(string $code, array $headers = ['X-Keyport-Version' => '1']): TestResponse
{
    return test()->postJson(route('keyport.pair'), [
        'pairing_code' => $code,
        'device_name' => 'Front desk PC',
        'platform' => 'windows-amd64',
        'agent_version' => '0.1.0',
    ], $headers);
}

it('pairs with a code and returns the token once', function () {
    ['owner' => $owner, 'property' => $property] = keyportSetup();
    $code = app(CreateKeyportPairingCode::class)($owner, $property);

    $response = keyportPair($code)->assertOk()->assertJson([
        'property_code' => $property->code,
        'property_name' => $property->name,
        'poll_seconds' => 0,
    ]);

    $token = $response->json('device_token');
    $device = KeyportDevice::query()->where('public_id', $response->json('device_id'))->firstOrFail();

    expect($token)->toStartWith('kp_live_')
        ->and($device->property_id)->toBe($property->id)
        ->and($device->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($device->getAttributes()))->not->toContain($token);
});

it('accepts a code typed in lower case', function () {
    ['owner' => $owner, 'property' => $property] = keyportSetup();
    $code = app(CreateKeyportPairingCode::class)($owner, $property);

    keyportPair(strtolower($code))->assertOk();
});

it('saves only a hash of the pairing code', function () {
    ['owner' => $owner, 'property' => $property] = keyportSetup();
    $code = app(CreateKeyportPairingCode::class)($owner, $property);

    expect(KeyportPairingCode::query()->firstOrFail()->code_hash)->toBe(hash('sha256', $code))
        ->and($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');
});

it('uses a code only once', function () {
    ['owner' => $owner, 'property' => $property] = keyportSetup();
    $code = app(CreateKeyportPairingCode::class)($owner, $property);

    keyportPair($code)->assertOk();
    keyportPair($code)->assertStatus(409)->assertJsonPath('error.code', 'pairing_code_used');

    expect(KeyportDevice::query()->where('property_id', $property->id)->count())->toBe(2);
});

it('refuses an expired code', function () {
    ['owner' => $owner, 'property' => $property] = keyportSetup();
    $code = app(CreateKeyportPairingCode::class)($owner, $property);

    $this->travel(11)->minutes();

    keyportPair($code)->assertStatus(410)->assertJsonPath('error.code', 'pairing_code_expired');
});

it('refuses a code that does not exist', function () {
    keyportPair('AAAA-AAAA')->assertNotFound()->assertJsonPath('error.code', 'invalid_pairing_code');
});

it('asks an old agent to update', function () {
    keyportPair('AAAA-AAAA', [])->assertStatus(426)->assertJsonPath('error.code', 'upgrade_required');
});

it('answers a bad request in the contract error shape', function () {
    $this->postJson(route('keyport.pair'), [], ['X-Keyport-Version' => '1'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});

it('stops the device when it unpairs', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();

    $this->postJson(route('keyport.unpair'), [], keyportHeaders($token))->assertNoContent();

    expect($device->refresh()->revoked_at)->not->toBeNull();
    $this->postJson(route('keyport.jobs.claim'), [], keyportHeaders($token))->assertUnauthorized();
});
