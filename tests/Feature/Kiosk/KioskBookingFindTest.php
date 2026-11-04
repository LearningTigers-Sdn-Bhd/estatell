<?php

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RegistrationCardFieldSetting;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskLookupLockout;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

require_once __DIR__.'/../Support/KioskSetup.php';

it('finds a booking by reference', function () {
    ['unit' => $unit] = kioskStaySetup();

    kioskPost('/bookings/find', ['reference' => 'R-100'])
        ->assertOk()
        ->assertExactJson([
            'stay_id' => $unit->id,
            'booking_ref' => 'R-100',
            'guest_name' => 'Aisha Rahman',
            'arrival' => '2026-10-14',
            'departure' => '2026-10-16',
            'unit_type' => 'Deluxe Double',
            'steps_required' => ['id' => true, 'registration_card' => false],
        ]);
});

it('ignores letter case and spaces around the reference', function () {
    kioskStaySetup();

    kioskPost('/bookings/find', ['reference' => '  r-100 '])->assertOk()->assertJsonPath('booking_ref', 'R-100');
});

it('reports the pre-check-in settings in steps_required', function () {
    ['property' => $property] = kioskStaySetup();
    PropertySetting::query()->where('property_id', $property->id)->update(['card_required_for_check_in' => true]);
    RegistrationCardFieldSetting::factory()->create(['property_id' => $property->id, 'field' => RegistrationCardField::IdentityDocument, 'visibility' => FieldVisibility::Optional]);

    kioskPost('/bookings/find', ['reference' => 'R-100'])
        ->assertOk()
        ->assertJsonPath('steps_required', ['id' => false, 'registration_card' => true]);
});

it('answers booking_not_found for an unknown reference', function () {
    kioskStaySetup();

    kioskPost('/bookings/find', ['reference' => 'R-999'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'booking_not_found');
});

it('answers booking_not_found for a booking of another property', function () {
    kioskStaySetup();
    $other = Property::factory()->create();
    kioskStayFor($other, 'R-200');

    kioskPost('/bookings/find', ['reference' => 'R-200'])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('answers booking_not_found for a body with no reference', function (array $body) {
    kioskStaySetup();

    kioskPost('/bookings/find', $body)->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
})->with([
    'empty' => [[]],
    'blank reference' => [['reference' => '']],
    'reference is a list' => [['reference' => ['R-100']]],
]);

it('has no QR token to look up, so a qr_token finds nothing', function () {
    kioskStaySetup();

    kioskPost('/bookings/find', ['qr_token' => 'R-100'])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

it('answers arrival_not_reached before the arrival day', function () {
    kioskStaySetup(['arrival_date' => '2026-10-15', 'departure_date' => '2026-10-17']);

    kioskPost('/bookings/find', ['reference' => 'R-100'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'arrival_not_reached');
});

it('does not find a stay that is checked in, cancelled or already over', function (array $unit) {
    kioskStaySetup($unit);

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
})->with([
    'checked in' => [['status' => 'checked_in', 'checked_in_at' => '2026-10-14 10:00:00']],
    'cancelled' => [['status' => 'cancelled']],
    'departure day reached' => [['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']],
]);

it('picks the stay that arrives first when a reservation has several', function () {
    ['reservation' => $reservation, 'unit' => $first] = kioskStaySetup(['arrival_date' => '2026-10-14']);
    ReservationUnit::factory()->create(['reservation_id' => $reservation->id, 'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-18']);

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertOk()->assertJsonPath('stay_id', $first->id);
});

it('refuses a missing, wrong or revoked token', function () {
    ['kiosk' => $kiosk] = kioskStaySetup();

    kioskPost('/bookings/find', ['reference' => 'R-100'], null)->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    kioskPost('/bookings/find', ['reference' => 'R-100'], 'est_live_wrongwrongwrongwrongwrongwrongwrong0000')->assertUnauthorized();

    $kiosk->update(['revoked_at' => now()]);
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('refuses a token without the kiosk scope', function () {
    kioskStaySetup();
    PersonalAccessToken::query()->update(['abilities' => ['v1:read']]);

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});

it('locks a kiosk out after repeated failed lookups, whatever it asks, and keeps the answer the same', function () {
    kioskStaySetup();

    foreach (range(1, KioskLookupLockout::MAX_FAILURES) as $guess) {
        kioskPost('/bookings/find', ['reference' => "X-{$guess}"])->assertNotFound();
    }

    $existing = kioskPost('/bookings/find', ['reference' => 'R-100']);
    $unknown = kioskPost('/bookings/find', ['reference' => 'X-999']);

    $existing->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    expect($existing->json())->toBe($unknown->json());
});

it('lets the kiosk look up again once the lockout runs out', function () {
    kioskStaySetup();

    foreach (range(1, KioskLookupLockout::MAX_FAILURES) as $guess) {
        kioskPost('/bookings/find', ['reference' => "X-{$guess}"]);
    }
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(429);

    $this->travel(KioskLookupLockout::DECAY_SECONDS + 1)->seconds();

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertOk();
});

it('does not reset the failure count when a booking is found', function () {
    kioskStaySetup();
    $max = KioskLookupLockout::MAX_FAILURES;

    foreach (range(1, $max - 1) as $guess) {
        kioskPost('/bookings/find', ['reference' => "X-{$guess}"])->assertNotFound();
    }
    // a found booking costs nothing, and does not wipe the failures either
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertOk();
    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertOk();
    kioskPost('/bookings/find', ['reference' => 'X-last'])->assertNotFound();

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(429);
});

it('counts an early arrival as a failed lookup too, so it cannot be used to confirm references', function () {
    kioskStaySetup(['arrival_date' => '2026-10-15', 'departure_date' => '2026-10-17']);

    foreach (range(1, KioskLookupLockout::MAX_FAILURES) as $ignored) {
        kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(409);
    }

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(429);
});

it('keeps the lockout per kiosk', function () {
    ['property' => $property] = kioskStaySetup();
    Kiosk::factory()->for($property)->withToken('est_live_secondkioskaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();

    foreach (range(1, KioskLookupLockout::MAX_FAILURES) as $guess) {
        kioskPost('/bookings/find', ['reference' => "X-{$guess}"]);
    }

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(429);
    kioskPost('/bookings/find', ['reference' => 'R-100'], 'est_live_secondkioskaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->assertOk();
});

it('never lets a refund take the failure count below zero', function () {
    ['kiosk' => $kiosk] = kioskStaySetup();
    $lockout = app(KioskLookupLockout::class);
    $key = 'kiosk-lookup-failures:'.$kiosk->id;
    $max = KioskLookupLockout::MAX_FAILURES;

    // the window ran out between the attempt and the refund of a found lookup
    $lockout->attempt($kiosk);
    $this->travel(KioskLookupLockout::DECAY_SECONDS + 1)->seconds();
    $lockout->refund($kiosk);
    $lockout->refund($kiosk);

    expect(RateLimiter::attempts($key))->toBe(0);

    foreach (range(1, $max) as $guess) {
        kioskPost('/bookings/find', ['reference' => "X-{$guess}"])->assertNotFound();
    }

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertStatus(429);
});
