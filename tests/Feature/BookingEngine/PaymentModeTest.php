<?php

require_once __DIR__.'/../Support/CurlecSetup.php';

use App\Actions\BookingEngine\SetUpBookingSite;
use App\Actions\Payments\RefundHeldPayment;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMode;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\BookingHold;
use App\Models\GatewayEvent;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookingEngine\OnlineBookingMoney;
use App\Services\BookingEngine\TestBookings;
use App\Services\Payments\CurlecGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

const CURLEC_TEST_WEBHOOK_SECRET = 'test-mode-webhook-secret';

/**
 * `curlecSetup()` with the test keys set and the property's booking website in Test payment mode.
 *
 * @return array<string, mixed>
 */
function testModeSetup(): array
{
    $setup = curlecSetup();
    config(['services.curlec.test' => ['key_id' => 'rzp_test_mode', 'key_secret' => 'test-mode-key-secret', 'webhook_secret' => CURLEC_TEST_WEBHOOK_SECRET]]);
    $setup['site']->update(['payment_mode' => PaymentMode::Test]);

    return $setup;
}

/**
 * @param  array<string, mixed>  $body
 */
function postTestModeWebhook(array $body, string $secret = CURLEC_TEST_WEBHOOK_SECRET): TestResponse
{
    return test()->postJson('/webhooks/curlec/test', $body, [
        'X-Razorpay-Event-Id' => 'evt_test_1',
        'X-Razorpay-Signature' => hash_hmac('sha256', (string) json_encode($body), $secret),
    ]);
}

// Taking a payment

test('a Test site makes its order with the test keys and the payment keeps the mode', function () {
    $setup = testModeSetup();
    $hold = curlecHold($setup);

    test()->postJson("/api/booking/v1/holds/{$hold->token}/payment", [], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN])
        ->assertOk()
        ->assertJsonPath('key_id', 'rzp_test_mode')
        ->assertJsonPath('test_mode', true);

    expect($setup['gateway']->orders[0]['mode'])->toBe(PaymentMode::Test)
        ->and(GatewayPayment::query()->sole()->mode)->toBe(PaymentMode::Test);
});

test('the gateway calls Curlec with the test keys in Test mode', function () {
    config([
        'services.curlec.base_url' => 'https://curlec.test/v1',
        'services.curlec.key_id' => 'live-key',
        'services.curlec.key_secret' => 'live-secret',
        'services.curlec.test' => ['key_id' => 'test-key', 'key_secret' => 'test-secret', 'webhook_secret' => ''],
    ]);
    Http::fake(['curlec.test/*' => Http::response(['id' => 'order_real'])]);

    app(CurlecGateway::class)->createOrder(PaymentMode::Test, 20000, 'MYR', 'hold-1', 'the-token');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Basic '.base64_encode('test-key:test-secret')));
});

test('a Test site with no test keys never falls back to the live keys', function () {
    config(['services.curlec.key_secret' => 'live-secret', 'services.curlec.test.key_secret' => null]);

    $signature = hash_hmac('sha256', 'order_1|pay_1', 'live-secret');

    expect(app(CurlecGateway::class)->verifySignature(PaymentMode::Test, 'order_1', 'pay_1', $signature))->toBeFalse()
        ->and(app(CurlecGateway::class)->verifySignature(PaymentMode::Live, 'order_1', 'pay_1', $signature))->toBeTrue();
});

test('a new booking website starts in Test mode', function () {
    $property = Property::factory()->create();

    expect(app(SetUpBookingSite::class)($property)->payment_mode)->toBe(PaymentMode::Test);
});

// The webhook

test('the test webhook checks the test secret and books the paid hold', function () {
    $setup = testModeSetup();
    $hold = curlecHold($setup);
    GatewayPayment::factory()->create(['booking_hold_id' => $hold->id, 'property_id' => $hold->property_id, 'order_id' => 'order_abc', 'amount' => $hold->total_amount, 'mode' => PaymentMode::Test]);

    postTestModeWebhook(curlecOrderPaid())->assertOk();

    expect(GatewayEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and($hold->refresh()->reservation_id)->not->toBeNull();
});

test('a test-signed event never confirms a live order', function () {
    $setup = testModeSetup();
    $hold = curlecHold($setup);
    GatewayPayment::factory()->create(['booking_hold_id' => $hold->id, 'property_id' => $hold->property_id, 'order_id' => 'order_abc', 'amount' => $hold->total_amount, 'mode' => PaymentMode::Live]);

    postTestModeWebhook(curlecOrderPaid())->assertOk();

    expect(GatewayEvent::query()->sole())->mode->toBe(PaymentMode::Test)->processed_at->not->toBeNull()
        ->and(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Created)
        ->and($hold->refresh()->reservation_id)->toBeNull();
});

test('each webhook route accepts only its own secret', function () {
    testModeSetup();

    postTestModeWebhook(curlecOrderPaid(), secret: CURLEC_WEBHOOK_SECRET)->assertStatus(400);
    postCurlecWebhook(curlecOrderPaid(), signature: hash_hmac('sha256', (string) json_encode(curlecOrderPaid()), CURLEC_TEST_WEBHOOK_SECRET))->assertStatus(400);

    expect(GatewayEvent::query()->count())->toBe(0);
});

// After the booking

test('a test payment is refunded with the test keys after the site went Live', function () {
    $setup = testModeSetup();
    $hold = curlecHold($setup);
    GatewayPayment::factory()->create(['booking_hold_id' => $hold->id, 'property_id' => $hold->property_id, 'order_id' => 'order_abc', 'amount' => $hold->total_amount, 'mode' => PaymentMode::Test]);
    postTestModeWebhook(curlecOrderPaid())->assertOk();
    $setup['site']->update(['payment_mode' => PaymentMode::Live]);
    $staff = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::PaymentRefund])->create();

    $result = app(RefundHeldPayment::class)($staff, Payment::query()->sole(), '200.00', null, 'key-1');

    $refund = GatewayRefund::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($setup['gateway']->refunds[0]['mode'])->toBe(PaymentMode::Test)
        ->and($setup['gateway']->refunds[0]['receipt'])->toBe("refund-{$refund->id}-{$refund->created_at->getTimestamp()}-1");
});

test('a test booking has no commission and shows a Test badge', function () {
    $setup = testModeSetup();
    $hold = curlecHold($setup);
    GatewayPayment::factory()->create(['booking_hold_id' => $hold->id, 'property_id' => $hold->property_id, 'order_id' => 'order_abc', 'amount' => $hold->total_amount, 'mode' => PaymentMode::Test]);
    postTestModeWebhook(curlecOrderPaid())->assertOk();
    $reservation = Reservation::query()->findOrFail(BookingHold::query()->sole()->reservation_id);

    expect(app(OnlineBookingMoney::class)->forReservation($reservation))->toBeNull()
        ->and(app(OnlineBookingMoney::class)->heldOutsideLedger($setup['property']->id))->toBe('0.00')
        ->and(app(TestBookings::class)->isTest($reservation))->toBeTrue();

    $staff = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($staff)->get(route('reservations.show', [$setup['property'], $reservation]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('testBooking.value', 'test'));
});

// The switch

/**
 * @return array{owner: User, property: Property}
 */
function paymentModeOwner(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(BookingEngine::class);
    app(SetUpBookingSite::class)($property);

    return compact('owner', 'property');
}

test('the switch saves the mode once and logs it', function () {
    ['owner' => $owner, 'property' => $property] = paymentModeOwner();

    $this->actingAs($owner)->put(route('settings.booking-website.payment-mode.update', $property), ['payment_mode' => 'live'])->assertRedirect();
    $this->actingAs($owner)->put(route('settings.booking-website.payment-mode.update', $property), ['payment_mode' => 'live'])->assertRedirect();

    expect($property->bookingSite()->sole()->payment_mode)->toBe(PaymentMode::Live)
        ->and(Activity::query()->where('event', 'booking-website.payment-mode-set')->count())->toBe(1);

    $this->actingAs($owner)->get(route('settings.booking-website.general', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('general.paymentMode', 'live')
            ->where('canChangePaymentMode', true)
            ->where('bookingWebsiteHeader.testPayments', null));
});

test('the switch needs the payment mode permission', function () {
    ['property' => $property] = paymentModeOwner();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::BookingWebsiteManage])->create();

    $this->actingAs($staff)->put(route('settings.booking-website.payment-mode.update', $property), ['payment_mode' => 'live'])->assertForbidden();
    $this->actingAs($staff)->get(route('settings.booking-website.general', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('general.paymentMode', 'test')
            ->where('canChangePaymentMode', false)
            ->where('bookingWebsiteHeader.testPayments.value', 'test'));
});

test('the switch answers 404 for another property', function () {
    ['owner' => $owner] = paymentModeOwner();
    $other = Property::factory()->create();

    $this->actingAs($owner)->put(route('settings.booking-website.payment-mode.update', $other), ['payment_mode' => 'live'])->assertNotFound();
});
