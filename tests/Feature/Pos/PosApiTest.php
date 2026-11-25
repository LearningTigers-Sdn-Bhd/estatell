<?php

use App\Features\Pos;
use App\Models\FolioLine;
use App\Models\Kiosk;
use App\Models\PosApiClient;
use App\Models\PosOutlet;
use App\Models\PosPosting;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/PosSetup.php';

const POS_TOKEN = 'est_live_posclienttokenaaaaaaaaaaaaaaaaaaaaaaaa';

/** @param array<string, mixed> $setup */
function posApi(array $setup, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    PosApiClient::query()->where('property_id', $setup['property']->id)->exists()
        || PosApiClient::factory()->for($setup['property'])->withToken(POS_TOKEN)->create();

    return test()->json($method, '/api/pos/v1/'.$path, $body, ['Authorization' => 'Bearer '.POS_TOKEN, ...$headers]);
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function roomChargeBody(array $setup, array $overrides = []): array
{
    return [
        'outlet_code' => $setup['outlet']->code,
        'external_receipt_id' => 'rcpt_9f8a7b6c',
        'external_receipt_number' => '1042',
        'reservation_unit_id' => $setup['unit']->id,
        'amount' => '85.50',
        'raw_payload' => ['items' => [['name' => 'Nasi Lemak Royale', 'qty' => 2, 'amount' => '85.50']]],
        ...$overrides,
    ];
}

it('lists the active outlets of the token property only', function () {
    $setup = posSetup();
    posSetup();
    PosOutlet::factory()->inactive()->create(['property_id' => $setup['property']->id]);

    posApi($setup, 'GET', 'outlets')
        ->assertOk()
        ->assertJsonCount(1, 'outlets')
        ->assertJsonPath('outlets.0.code', $setup['outlet']->code);
});

it('refuses a missing, kiosk or revoked token', function () {
    $setup = posSetup();
    Kiosk::factory()->for($setup['property'])->withToken('est_live_kiosktokenaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();
    PosApiClient::factory()->for($setup['property'])->withToken('est_live_revokedaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->revoked()->create();

    $this->getJson('/api/pos/v1/outlets')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
    $this->getJson('/api/pos/v1/outlets', ['Authorization' => 'Bearer est_live_kiosktokenaaaaaaaaaaaaaaaaaaaaaaaaaaa'])->assertUnauthorized();
    $this->getJson('/api/pos/v1/outlets', ['Authorization' => 'Bearer est_live_revokedaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])->assertUnauthorized();
});

it('does not let a pos token reach the kiosk api', function () {
    $setup = posSetup();
    PosApiClient::factory()->for($setup['property'])->withToken(POS_TOKEN)->create();

    $this->getJson('/api/kiosk/v1/me', ['Authorization' => 'Bearer '.POS_TOKEN])->assertUnauthorized();
});

it('answers 403 pos_disabled when the feature is off', function () {
    $setup = posSetup();
    PosApiClient::factory()->for($setup['property'])->withToken(POS_TOKEN)->create();
    Feature::for($setup['property'])->deactivate(Pos::class);

    $this->getJson('/api/pos/v1/outlets', ['Authorization' => 'Bearer '.POS_TOKEN])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'pos_disabled');
});

it('finds in-house guests by reservation reference and hides other properties', function () {
    $setup = posSetup();
    posSetup();
    $reference = $setup['unit']->reservation->reference;

    posApi($setup, 'GET', 'in-house-guests?q='.$reference)
        ->assertOk()
        ->assertJsonCount(1, 'guests')
        ->assertJsonPath('guests.0.reservation_unit_id', $setup['unit']->id)
        ->assertJsonPath('guests.0.can_post_charges', true)
        ->assertJsonMissingPath('guests.0.nationality_code');
});

it('posts a room charge, then replays it by receipt id and by idempotency key', function () {
    $setup = posSetup();

    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup), ['Idempotency-Key' => 'key-1'])
        ->assertCreated()
        ->assertJsonPath('status', 'posted')
        ->assertJsonPath('amount', '85.50')
        ->assertJsonPath('description', 'Main Restaurant — Receipt #1042');

    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup), ['Idempotency-Key' => 'key-1'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup))
        ->assertOk()
        ->assertJsonPath('status', 'posted');

    expect(PosPosting::query()->count())->toBe(1)
        ->and(FolioLine::query()->whereNull('parent_line_id')->count())->toBe(1);
});

it('answers 202 pending_review for a receipt it could not match', function () {
    $setup = posSetup();
    $other = posSetup();

    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup, ['reservation_unit_id' => $other['unit']->id]))
        ->assertAccepted()
        ->assertJsonPath('status', 'pending_review')
        ->assertJsonPath('failure_reason', 'room_not_matched');
});

it('answers 404 outlet_not_found for an unknown, inactive or other property outlet', function () {
    $setup = posSetup();
    $other = posSetup();
    $setup['outlet']->forceFill(['active' => false])->save();

    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup))->assertNotFound()->assertJsonPath('error.code', 'outlet_not_found');
    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup, ['outlet_code' => $other['outlet']->code]))->assertNotFound();
});

it('answers 422 invalid_fields for a bad amount', function () {
    $setup = posSetup();

    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup, ['amount' => '0.00']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_fields')
        ->assertJsonStructure(['error' => ['fields' => ['amount']]]);
});

it('voids a posted charge, and a repeated void answers the same', function () {
    $setup = posSetup();
    posApi($setup, 'POST', 'room-charges', roomChargeBody($setup))->assertCreated();
    $void = ['outlet_code' => $setup['outlet']->code, 'void_receipt_id' => 'void_1a2b3c', 'reason' => 'Guest disputed item'];

    posApi($setup, 'POST', 'room-charges/rcpt_9f8a7b6c/void', $void)->assertOk()->assertJsonPath('status', 'reversed');
    posApi($setup, 'POST', 'room-charges/rcpt_9f8a7b6c/void', $void)->assertOk()->assertJsonPath('status', 'reversed');

    expect(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(2);
});

it('answers 202 for a void it cannot reverse on its own', function () {
    $setup = posSetup();

    posApi($setup, 'POST', 'room-charges/unknown/void', ['outlet_code' => $setup['outlet']->code, 'void_receipt_id' => 'void_1'])
        ->assertAccepted()
        ->assertJsonPath('status', 'pending_review')
        ->assertJsonPath('failure_reason', 'original_not_found');
});
