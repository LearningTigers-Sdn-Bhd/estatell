<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\RatePlan;
use Illuminate\Testing\TestResponse;

/**
 * Prices rooms for two nights, 2026-10-20 to 2026-10-22, by default one room at the RM 100 a night of the setup.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $body
 */
function postPricing(array $setup, array $body = []): TestResponse
{
    return bookingApiPost('properties/'.$setup['property']->code.'/pricing', [
        'arrival' => '2026-10-20',
        'departure' => '2026-10-22',
        'lines' => [pricingLine($setup)],
        ...$body,
    ]);
}

/**
 * @param  array<string, mixed>  $setup
 * @return array<string, mixed>
 */
function pricingLine(array $setup): array
{
    return ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2];
}

/**
 * @return array<string, mixed>
 */
function pricingSetup(): array
{
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    return $setup;
}

it('adds up the lines, and keeps them in order', function () {
    $setup = pricingSetup();

    $response = postPricing($setup, ['lines' => [
        pricingLine($setup),
        ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1],
    ]])->assertOk();

    expect($response->json())
        ->currency->toBe('MYR')
        ->online_total->toBe('280.00')
        ->pay_at_property->toBe('0.00');
    expect(array_column($response->json('lines'), 'pay_online'))->sequence(
        fn ($line) => $line->total->toBe('200.00'),
        fn ($line) => $line->total->toBe('80.00'),
    );
});

it('splits a foreign guest\'s desk taxes, and prices no nationality as a local guest', function () {
    $setup = pricingSetup();
    bookingApiTaxes($setup['property'], $setup['roomCode']);
    $twoRooms = ['lines' => [pricingLine($setup), pricingLine($setup)]];

    expect(postPricing($setup, [...$twoRooms, 'nationality' => 'SG'])->assertOk()->json())
        ->online_total->toBe('432.00')
        ->pay_at_property->toBe('40.00');
    expect(postPricing($setup, $twoRooms)->assertOk()->json())
        ->online_total->toBe('432.00')
        ->pay_at_property->toBe('0.00');
});

it('answers the total a hold accepts', function () {
    $setup = pricingSetup();
    $line = pricingLine($setup);
    $priced = postPricing($setup, ['lines' => [$line, $line]])->assertOk();

    test()->postJson('/api/booking/v1/properties/'.$setup['property']->code.'/holds', [
        'arrival' => '2026-10-20',
        'departure' => '2026-10-22',
        'total' => $priced->json('online_total'),
        'guest' => ['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '0123456789', 'nationality' => 'MY'],
        'lines' => [$line, $line],
    ], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN, 'X-Guest-IP' => '203.0.113.10', 'Idempotency-Key' => 'pricing-test'])->assertCreated();

    expect(BookingHold::query()->sole()->total_amount)->toEqual($priced->json('online_total'));
});

it('keeps no rooms', function () {
    $setup = pricingSetup();

    postPricing($setup)->assertOk();

    expect(BookingHold::query()->count())->toBe(0);
});

it('refuses what a hold would refuse', function (string $code, Closure $body) {
    $setup = pricingSetup();

    postPricing($setup, $body($setup))->assertUnprocessable()->assertJsonPath('error.code', $code);
})->with([
    'a plan that does not sell the room type' => ['plan_not_available', fn (array $setup) => ['lines' => [['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 1]]]],
    'a plan of another property' => ['plan_not_available', fn (array $setup) => ['lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => RatePlan::factory()->create()->id, 'adults' => 1]]]],
    'too many adults' => ['too_many_adults', fn (array $setup) => ['lines' => [[...pricingLine($setup), 'adults' => 40]]]],
    'more lines than rooms' => ['not_available', fn (array $setup) => ['lines' => [pricingLine($setup), pricingLine($setup), pricingLine($setup)]]],
    'an arrival past the window' => ['outside_window', fn () => ['arrival' => '2028-01-01', 'departure' => '2028-01-02']],
]);

it('validates the stay, the nationality and the lines', function (string $field, array $body) {
    postPricing(pricingSetup(), $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no lines' => ['lines', ['lines' => []]],
    'an unknown nationality' => ['nationality', ['nationality' => 'ZZ']],
    'a lowercase nationality' => ['nationality', ['nationality' => 'my']],
    'a departure before the arrival' => ['departure', ['departure' => '2026-10-19']],
    'no adults' => ['lines.0.adults', ['lines' => [['room_type_id' => 1, 'rate_plan_id' => 1, 'adults' => 0]]]],
]);

it('closes pricing to a token without the ability and to properties out of reach', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient([BookingClient::ABILITY_SITE]);

    postPricing($setup)->assertUnauthorized();

    BookingClient::query()->sole()->tokens()->delete();
    BookingClient::query()->sole()->update(['revoked_at' => now()]);
    bookingMicrositeClient();
    $setup['site']->forceFill(['published_at' => null])->save();

    postPricing($setup)->assertNotFound()->assertJsonPath('error.code', 'property_not_found');
});
