<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Models\ApiIdempotencyKey;
use App\Models\BookingClient;
use App\Models\Kiosk;
use App\Models\Property;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

/**
 * A route made here shows how the middleware behaves, apart from any one write: each
 * run adds one to the counter, and the answer shows the count.
 */
beforeEach(function () {
    $this->runs = 0;

    Route::post('api/booking/v1/properties/{code}/idempotency-probe', function () {
        $this->runs++;

        return response()->json(['run' => $this->runs], 201);
    })->middleware(['api', 'api.token:booking:content', 'api.booking.reach', 'api.idempotent']);

    Route::post('api/booking/v1/idempotency-probe', function () {
        $this->runs++;

        return response()->json(['run' => $this->runs], 422);
    })->middleware(['api', 'api.token:booking:content', 'api.idempotent']);
});

function probe(string $path, string $key, string $token = BOOKING_API_TOKEN)
{
    return test()->postJson('/api/booking/v1/'.$path, [], ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key]);
}

it('replays the first answer to a booking client and runs once', function () {
    /** @var Property $property */
    ['property' => $property] = bookingApiProperty();
    $client = bookingMicrositeClient();
    $path = 'properties/'.$property->code.'/idempotency-probe';

    probe($path, 'key-1')->assertCreated()->assertJsonPath('run', 1)->assertHeaderMissing('Idempotent-Replayed');
    probe($path, 'key-1')->assertCreated()->assertJsonPath('run', 1)->assertHeader('Idempotent-Replayed', 'true');

    $stored = ApiIdempotencyKey::query()->sole();

    expect($this->runs)->toBe(1)
        ->and($stored->booking_client_id)->toBe($client->id)
        ->and($stored->kiosk_id)->toBeNull()
        ->and($stored->property_id)->toBe($property->id);
});

it('runs again for a new key', function () {
    ['property' => $property] = bookingApiProperty();
    bookingMicrositeClient();
    $path = 'properties/'.$property->code.'/idempotency-probe';

    probe($path, 'key-1');
    probe($path, 'key-2')->assertJsonPath('run', 2)->assertHeaderMissing('Idempotent-Replayed');
});

it('replays a refusal, and stores no property for a path without one', function () {
    bookingApiProperty();
    bookingMicrositeClient();

    probe('idempotency-probe', 'key-1')->assertUnprocessable();
    probe('idempotency-probe', 'key-1')->assertUnprocessable()->assertJsonPath('run', 1)->assertHeader('Idempotent-Replayed', 'true');

    expect(ApiIdempotencyKey::query()->sole()->property_id)->toBeNull();
});

it('does not share a key between two booking clients', function () {
    bookingApiProperty();
    bookingMicrositeClient();
    BookingClient::factory()->withToken('est_live_otherclientaaaaaaaaaaaaaaaaaaaaaaaaaa')->create(['type' => 'demo']);

    probe('idempotency-probe', 'key-1');
    probe('idempotency-probe', 'key-1', 'est_live_otherclientaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->assertJsonPath('run', 2)
        ->assertHeaderMissing('Idempotent-Replayed');
});

it('requires a stored answer to have exactly one owner', function () {
    ['property' => $property] = bookingApiProperty();
    $client = bookingMicrositeClient();
    $kiosk = Kiosk::factory()->for($property)->create();
    $answer = ['key' => 'key-1', 'path' => 'p', 'status' => 200, 'body' => '{}', 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => ApiIdempotencyKey::query()->insert($answer))->toThrow(QueryException::class)
        ->and(fn () => ApiIdempotencyKey::query()->insert([...$answer, 'kiosk_id' => $kiosk->id, 'booking_client_id' => $client->id]))->toThrow(QueryException::class);
});
