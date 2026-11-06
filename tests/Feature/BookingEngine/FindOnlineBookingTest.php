<?php

require_once __DIR__.'/../Support/CurlecSetup.php';

use App\Actions\BookingEngine\PurgeBookingHoldGuestDetails;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * A confirmed online booking, booked by aisyah@example.com.
 *
 * @return array{property: Property, hold: BookingHold, reservation: Reservation}
 */
function foundBooking(): array
{
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    curlecOrder($hold);
    postCurlecWebhook(curlecOrderPaid())->assertOk();
    $hold->refresh();

    return ['property' => $setup['property'], 'hold' => $hold, 'reservation' => Reservation::query()->findOrFail($hold->reservation_id)];
}

/**
 * @param  array<string, string>  $body
 */
function findBooking(Property $property, array $body, string $guestIp = '203.0.113.9'): TestResponse
{
    return test()->postJson('/api/booking/v1/properties/'.$property->code.'/bookings/find', $body, [
        'Authorization' => 'Bearer '.BOOKING_API_TOKEN,
        'X-Guest-IP' => $guestIp,
    ]);
}

test('the code and email answer with the booking token', function () {
    ['property' => $property, 'hold' => $hold, 'reservation' => $reservation] = foundBooking();

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'])
        ->assertOk()
        ->assertExactJson(['token' => $hold->token]);
});

test('the code and email ignore case and spaces', function () {
    ['property' => $property, 'hold' => $hold, 'reservation' => $reservation] = foundBooking();

    findBooking($property, ['short_code' => ' '.mb_strtolower($reservation->short_code).' ', 'email' => 'AISYAH@Example.com'])
        ->assertOk()
        ->assertJsonPath('token', $hold->token);
});

test('the lookup still works after the hold loses its guest details', function () {
    ['property' => $property, 'hold' => $hold, 'reservation' => $reservation] = foundBooking();
    $this->travel(PurgeBookingHoldGuestDetails::AFTER_DAYS + 1)->days();
    app(PurgeBookingHoldGuestDetails::class)();

    expect($hold->refresh()->guest_email)->toBeNull();

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'])
        ->assertOk()
        ->assertJsonPath('token', $hold->token);
});

test('a wrong code and a wrong email answer the same 404', function () {
    ['property' => $property, 'reservation' => $reservation] = foundBooking();

    $wrongCode = findBooking($property, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com'])->assertNotFound();
    $wrongEmail = findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'someone@example.com'])->assertNotFound();

    expect($wrongCode->json())->toBe($wrongEmail->json())
        ->and($wrongCode->json('error.code'))->toBe('booking_not_found')
        ->and($wrongCode->content())->not->toContain('token"');
});

test('a booker with no email is not found', function () {
    ['property' => $property, 'reservation' => $reservation] = foundBooking();
    Guest::query()->whereKey($reservation->booker_guest_id)->update(['email' => null]);

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'])->assertNotFound();
});

test('a booking with no online hold is not found', function () {
    ['property' => $property] = foundBooking();
    $guest = Guest::factory()->create(['property_id' => $property->id, 'email' => 'desk@example.com']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'booker_guest_id' => $guest->id]);

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'desk@example.com'])->assertNotFound();
});

test('another property or another client cannot find the booking', function () {
    ['property' => $property, 'hold' => $hold, 'reservation' => $reservation] = foundBooking();
    $body = ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'];
    $other = bookingApiProperty('other-bay')['property'];

    findBooking($other, $body)->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');

    $hold->forceFill(['booking_client_id' => BookingClient::factory()->apiOnly($property)->create()->id])->save();
    findBooking($property, $body)->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
});

test('a property the client cannot reach answers property_not_found', function () {
    ['property' => $property, 'reservation' => $reservation] = foundBooking();
    Feature::for($property)->deactivate(BookingEngine::class);

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'property_not_found');
});

test('a code and a valid email are required', function () {
    ['property' => $property] = foundBooking();

    findBooking($property, ['short_code' => '', 'email' => 'not-an-email'])->assertUnprocessable();
});

test('the eleventh failure locks the address out, even with the right details', function () {
    ['property' => $property, 'reservation' => $reservation] = foundBooking();

    foreach (range(1, 10) as $ignored) {
        findBooking($property, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com'])->assertNotFound();
    }

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'])
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');

    findBooking($property, ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'], guestIp: '203.0.113.10')->assertOk();
});

test('the lockout leaves the booking endpoints and other properties alone', function () {
    ['property' => $property, 'hold' => $hold] = foundBooking();
    $other = bookingApiProperty('other-bay')['property'];

    foreach (range(1, 11) as $ignored) {
        findBooking($property, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com']);
    }

    bookingApiGet('bookings/'.$hold->token)->assertOk();
    findBooking($other, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com'])->assertNotFound();
});

test('a lookup that finds a booking does not count, and does not clear earlier failures', function () {
    ['property' => $property, 'reservation' => $reservation] = foundBooking();
    $right = ['short_code' => $reservation->short_code, 'email' => 'aisyah@example.com'];

    foreach (range(1, 9) as $ignored) {
        findBooking($property, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com']);
    }

    findBooking($property, $right)->assertOk();
    findBooking($property, $right)->assertOk();
    findBooking($property, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com'])->assertNotFound();
    findBooking($property, $right)->assertTooManyRequests();
});

test('the lookup does not use the shared api-token limit', function () {
    ['property' => $property] = foundBooking();

    foreach (range(1, 130) as $index) {
        findBooking($property, ['short_code' => 'ZZZZZZ', 'email' => 'aisyah@example.com'], guestIp: '198.51.100.'.($index % 200 + 1));
    }

    bookingApiGet('properties/'.$property->code)->assertOk();
});

test('the managed booking shows the short code', function () {
    ['hold' => $hold, 'reservation' => $reservation] = foundBooking();

    bookingApiGet('bookings/'.$hold->token)->assertOk()->assertJsonPath('short_code', $reservation->short_code);
});
