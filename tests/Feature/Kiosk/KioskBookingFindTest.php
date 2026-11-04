<?php

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use App\Models\ApiToken;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RegistrationCardFieldSetting;
use App\Models\ReservationUnit;

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
    ApiToken::query()->update(['scopes' => 'v1:read']);

    kioskPost('/bookings/find', ['reference' => 'R-100'])->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
});
