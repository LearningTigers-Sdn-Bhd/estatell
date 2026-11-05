<?php

require_once __DIR__.'/../Support/CurlecSetup.php';

use App\Actions\Catalogues\SeedPaymentMethods;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\ProcessCurlecEvent;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\BookingHoldStatus;
use App\Enums\BookingSourceType;
use App\Enums\FolioType;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Features\BookingEngine;
use App\Jobs\ProcessCurlecEventJob;
use App\Models\BookingClient;
use App\Models\BookingHoldLine;
use App\Models\BookingSource;
use App\Models\FolioLine;
use App\Models\GatewayEvent;
use App\Models\GatewayPayment;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OnlinePaymentNeedsAttention;
use App\Services\Availability\AvailabilityService;
use App\Services\Payments\CurlecGateway;
use App\Services\Property\CountryProfiles;
use App\Services\Reservations\ReservationFormOptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

function postHoldPayment(string $token, array $headers = []): TestResponse
{
    return test()->postJson("/api/booking/v1/holds/{$token}/payment", [], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN, ...$headers]);
}

// Starting a payment

test('starting a payment creates an order for the amount estatell calculated', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);

    postHoldPayment($hold->token)->assertOk()->assertExactJson([
        'key_id' => 'rzp_test_key',
        'order_id' => 'order_1',
        'amount' => 20000,
        'currency' => $setup['property']->settings->currency->getCurrencyCode(),
        'callback_url' => route('webhooks.curlec.return', ['token' => $hold->token]),
        'timeout' => 1200,
    ]);

    expect($setup['gateway']->orders)->toHaveCount(1)
        ->and(GatewayPayment::query()->sole())
        ->property_id->toBe($setup['property']->id)
        ->status->toBe(GatewayPaymentStatus::Created)
        ->gateway_account->toBe('primary')
        ->amount->toBe('200.00');
});

test('a guest who retries gets the same order, so they cannot pay twice', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);

    postHoldPayment($hold->token)->assertOk()->assertJsonPath('order_id', 'order_1');
    postHoldPayment($hold->token)->assertOk()->assertJsonPath('order_id', 'order_1');

    expect($setup['gateway']->orders)->toHaveCount(1)->and(GatewayPayment::query()->count())->toBe(1);
});

test('a repeat with the same idempotency key replays the first answer', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);

    postHoldPayment($hold->token, ['Idempotency-Key' => 'key-1'])->assertOk();
    postHoldPayment($hold->token, ['Idempotency-Key' => 'key-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($setup['gateway']->orders)->toHaveCount(1);
});

test('a hold that is not open cannot start a payment', function (string $state) {
    $setup = curlecSetup();

    postHoldPayment(curlecHold($setup, $state)->token)->assertStatus(422)->assertJsonPath('error.code', 'hold_not_open');

    expect($setup['gateway']->orders)->toBe([]);
})->with(['pastExpiry', 'expired', 'paid', 'confirmed']);

test('another client cannot start a payment on a hold', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    $hold->update(['booking_client_id' => BookingClient::factory()->demo()->create()->id]);

    postHoldPayment($hold->token)->assertNotFound()->assertJsonPath('error.code', 'hold_not_found');
});

test('a property the client no longer reaches cannot start a payment', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    Feature::for($setup['property'])->deactivate(BookingEngine::class);

    postHoldPayment($hold->token)->assertNotFound()->assertJsonPath('error.code', 'property_not_found');

    expect($setup['gateway']->orders)->toBe([]);
});

test('when Curlec is down the client can retry with the same key', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    $setup['gateway']->failing = true;

    postHoldPayment($hold->token, ['Idempotency-Key' => 'key-1'])->assertStatus(503)->assertJsonPath('error.code', 'payment_unavailable');
    expect(GatewayPayment::query()->count())->toBe(0);

    $setup['gateway']->failing = false;
    postHoldPayment($hold->token, ['Idempotency-Key' => 'key-1'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
});

test('the order sent to Curlec holds no guest details', function () {
    config(['services.curlec.base_url' => 'https://curlec.test/v1', 'services.curlec.key_id' => 'key', 'services.curlec.key_secret' => 'secret']);
    Http::fake(['curlec.test/*' => Http::response(['id' => 'order_real'])]);

    $orderId = app(CurlecGateway::class)->createOrder(20000, 'MYR', 'hold-1', 'the-token');

    expect($orderId)->toBe('order_real');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://curlec.test/v1/orders'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('key:secret'))
        && $request->data() === ['amount' => 20000, 'currency' => 'MYR', 'receipt' => 'hold-1', 'notes' => ['app' => 'estatell', 'hold' => 'the-token']]);
});

// The webhook

test('a bad signature answers 400 and saves nothing', function () {
    curlecSetup();

    postCurlecWebhook(curlecOrderPaid(), signature: 'wrong')->assertStatus(400);

    expect(GatewayEvent::query()->count())->toBe(0);
});

test('with no secret set every webhook is refused', function () {
    curlecSetup();
    config(['services.curlec.webhook_secret' => '']);

    postCurlecWebhook(curlecOrderPaid(), signature: hash_hmac('sha256', (string) json_encode(curlecOrderPaid()), ''))->assertStatus(400);

    expect(GatewayEvent::query()->count())->toBe(0);
});

test('order.paid creates the booking, its guest and the folio payment', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    curlecOrder($hold);
    $guestsBefore = Guest::query()->count();

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    $reservation = Reservation::query()->with('units')->sole();
    $payment = Payment::query()->with('folio')->sole();

    expect($hold->refresh())->status->toBe(BookingHoldStatus::Confirmed)->reservation_id->toBe($reservation->id)
        ->and(GatewayPayment::query()->sole())->status->toBe(GatewayPaymentStatus::Paid)->gateway_payment_id->toBe('pay_123')
        ->and(GatewayEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and($reservation->created_by)->toBeNull()
        ->and($reservation->notes)->toBe('Late arrival')
        ->and(BookingSource::query()->find($reservation->booking_source_id)->type)->toBe(BookingSourceType::BookingEngine)
        ->and(Guest::query()->count())->toBe($guestsBefore + 1)
        ->and(Guest::query()->find($reservation->booker_guest_id))->full_name->toBe('Aisyah Rahman')->nationality_code->toBe('MY')
        ->and($payment)
        ->amount->toBe('200.00')
        ->purpose->toBe(PaymentPurpose::Advance)
        ->reference->toBe('pay_123')
        ->received_by->toBeNull()
        ->payment_method_id->toBe($setup['online']->id)
        ->and($payment->receipt_number)->not->toBeNull()
        ->and($payment->folio->type)->toBe(FolioType::Unit)
        ->and(FolioLine::query()->where('idempotency_key', "online:order_abc:{$reservation->units->first()->id}")->count())->toBe(1);
});

test('a group booking gets one payment for each unit, on its own folio', function () {
    $setup = curlecSetup();
    curlecOrder(curlecHold($setup, rooms: 2));

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    $payments = Payment::query()->with('folio')->get();

    expect(Reservation::query()->sole()->units()->count())->toBe(2)
        ->and($payments)->toHaveCount(2)
        ->and($payments->pluck('amount')->all())->toBe(['200.00', '200.00'])
        ->and($payments->pluck('folio.type')->unique()->all())->toBe([FolioType::Unit])
        ->and($payments->pluck('folio_id')->unique())->toHaveCount(2);
});

test('a repeat of the event, or a new event for the same order, makes no second booking', function () {
    $setup = curlecSetup();
    curlecOrder(curlecHold($setup));

    postCurlecWebhook(curlecOrderPaid())->assertOk();
    postCurlecWebhook(curlecOrderPaid())->assertOk();
    postCurlecWebhook(curlecOrderPaid(), eventId: 'evt_2')->assertOk();

    expect(GatewayEvent::query()->count())->toBe(2)
        ->and(Reservation::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1);
});

test('an order estatell did not create is marked processed and ignored', function () {
    curlecSetup();

    postCurlecWebhook(curlecOrderPaid('order_of_someone_else'))->assertOk();

    expect(GatewayEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and(Reservation::query()->count())->toBe(0);
});

test('an event estatell does not use is marked processed', function () {
    curlecSetup();

    postCurlecWebhook(['event' => 'refund.processed', 'payload' => []])->assertOk();

    expect(GatewayEvent::query()->sole()->processed_at)->not->toBeNull();
});

test('a failed payment makes no booking, and the guest can retry on the same order', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    curlecOrder($hold);

    postCurlecWebhook(curlecPaymentFailed())->assertOk();

    expect(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Failed)
        ->and($hold->refresh()->status)->toBe(BookingHoldStatus::Held)
        ->and(Reservation::query()->count())->toBe(0);

    postHoldPayment($hold->token)->assertOk()->assertJsonPath('order_id', 'order_abc');
    postCurlecWebhook(curlecOrderPaid(), eventId: 'evt_2')->assertOk();

    expect(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and(Reservation::query()->count())->toBe(1);
});

test('a late payment.failed does not undo a paid order', function () {
    $setup = curlecSetup();
    curlecOrder(curlecHold($setup));

    postCurlecWebhook(curlecOrderPaid())->assertOk();
    postCurlecWebhook(curlecPaymentFailed(), eventId: 'evt_2')->assertOk();

    expect(GatewayPayment::query()->sole())->status->toBe(GatewayPaymentStatus::Paid)->gateway_payment_id->toBe('pay_123');
});

// The price the guest paid

test('a hold saves the stay price before tax', function () {
    $setup = curlecSetup();
    bookingApiTaxes($setup['property'], $setup['roomCode']);

    $this->postJson("/api/booking/v1/properties/{$setup['property']->code}/holds", [
        'arrival' => '2026-10-20',
        'departure' => '2026-10-22',
        'total' => '216.00',
        'guest' => ['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '0123456789', 'nationality' => 'MY'],
        'lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2]],
    ], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN, 'X-Guest-IP' => '203.0.113.10'])->assertCreated();

    expect(BookingHoldLine::query()->sole())->amount->toBe('216.00')->room_total->toBe('200.00');
});

test('a rate change after the hold does not change the price the guest paid', function () {
    $setup = curlecSetup();
    curlecOrder(curlecHold($setup));
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->update(['amount' => '150.00']);

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    $nights = Reservation::query()->sole()->units()->sole()->nights()->get();

    expect($nights->pluck('amount')->all())->toBe(['100.00', '100.00'])
        ->and($nights->pluck('override_amount')->all())->toBe(['100.00', '100.00'])
        ->and(Activity::query()->where('event', 'reservation.unit_added')->sole()->properties->get('override_reason'))->toBe('Booked online');
});

test('with no rate change the nights keep their own prices and no override', function () {
    $setup = curlecSetup();
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->where('date', '2026-10-21')->update(['amount' => '120.00']);
    $hold = curlecHold($setup);
    $hold->lines()->update(['room_total' => '220.00', 'amount' => '220.00']);
    $hold->update(['total_amount' => '220.00']);
    curlecOrder($hold);

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    $nights = Reservation::query()->sole()->units()->sole()->nights()->orderBy('date')->get();

    expect($nights->pluck('amount')->all())->toBe(['100.00', '120.00'])
        ->and($nights->pluck('override_amount')->all())->toBe([null, null]);
});

test('a hold with no saved price uses the rates when it confirms', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    $hold->lines()->update(['room_total' => null]);
    curlecOrder($hold);
    Rate::query()->where('rate_plan_id', $setup['plan']->id)->update(['amount' => '150.00']);

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    expect(Reservation::query()->sole()->units()->sole()->nights()->pluck('override_amount')->all())->toBe([null, null]);
});

// Paid after the hold expired

test('a late payment confirms when the rooms are still free', function (string $state) {
    $setup = curlecSetup();
    $hold = curlecHold($setup, $state);
    curlecOrder($hold);

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Confirmed)
        ->and(Reservation::query()->count())->toBe(1);
})->with(['pastExpiry', 'expired']);

test('a late payment whose rooms are taken makes no booking and alerts admins', function () {
    Notification::fake();
    $setup = curlecSetup();
    $admin = User::factory()->admin()->create();
    $hold = curlecHold($setup, 'expired');
    curlecOrder($hold);

    foreach ($setup['rooms'] as $room) {
        curlecHold($setup, 'paid');
    }

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Expired)
        ->and(GatewayPayment::query()->where('booking_hold_id', $hold->id)->sole()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and(GatewayEvent::query()->sole()->processed_at)->not->toBeNull()
        ->and(Reservation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(app(AvailabilityService::class)->availableForStay($setup['roomType'], $hold->arrival_date, $hold->departure_date))->toBe(0);

    Notification::assertSentTo($admin, OnlinePaymentNeedsAttention::class, fn (OnlinePaymentNeedsAttention $notification): bool => $notification->orderId === 'order_abc' && $notification->paymentId === 'pay_123');
});

// A refused confirmation

test('a refused booking stays unprocessed, keeps the rooms, alerts admins once and can run again', function () {
    Notification::fake();
    $setup = curlecSetup();
    $admin = User::factory()->admin()->create();
    $hold = curlecHold($setup);
    curlecOrder($hold);
    $setup['online']->update(['active' => false]);

    postCurlecWebhook(curlecOrderPaid())->assertOk();

    $event = GatewayEvent::query()->sole();

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Paid)
        ->and($hold->reservation_id)->toBeNull()
        ->and($event->processed_at)->toBeNull()
        ->and(Reservation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);

    Notification::assertSentToTimes($admin, OnlinePaymentNeedsAttention::class, 1);

    $setup['online']->update(['active' => true]);
    (new ProcessCurlecEventJob($event->id))->handle(app(ProcessCurlecEvent::class));

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Confirmed)
        ->and(Reservation::query()->count())->toBe(1)
        ->and($event->refresh()->processed_at)->not->toBeNull();
});

test('a retry that is refused again does not alert admins again', function () {
    Notification::fake();
    $setup = curlecSetup();
    User::factory()->admin()->create();
    curlecOrder(curlecHold($setup));
    $setup['online']->update(['active' => false]);

    $event = GatewayEvent::factory()->create(['payload' => curlecOrderPaid()]);

    expect(app(ProcessCurlecEvent::class)($event, isFirstAttempt: false))->toBeFalse();

    Notification::assertNothingSent();
});

// The guest's return

test('the guest returns to the hold page after a verified payment, and nothing is written', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    curlecOrder($hold);
    $signature = hash_hmac('sha256', 'order_abc|pay_123', CURLEC_KEY_SECRET);

    $this->post("/webhooks/curlec/return/{$hold->token}", ['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_123', 'razorpay_signature' => $signature])
        ->assertStatus(303)
        ->assertRedirect(rtrim((string) config('estatell.booking_site_url'), '/')."/sand-bay/book/{$hold->token}?payment=confirming");

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Held)
        ->and(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Created)
        ->and(Reservation::query()->count())->toBe(0);
});

test('a bad signature, another order or a failed payment returns as failed', function (array $body) {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    curlecOrder($hold);

    $this->post("/webhooks/curlec/return/{$hold->token}", $body)
        ->assertStatus(303)
        ->assertRedirectContains('payment=failed');

    expect(Reservation::query()->count())->toBe(0);
})->with([
    'bad signature' => [['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_123', 'razorpay_signature' => 'wrong']],
    'another order' => [['razorpay_order_id' => 'order_other', 'razorpay_payment_id' => 'pay_123', 'razorpay_signature' => hash_hmac('sha256', 'order_other|pay_123', CURLEC_KEY_SECRET)]],
    'failed payment' => [['error' => ['code' => 'BAD_REQUEST_ERROR', 'reason' => 'payment_failed']]],
]);

test('an unknown hold token returns 404', function () {
    curlecSetup();

    $this->post('/webhooks/curlec/return/unknown')->assertNotFound();
});

// The Online method

test('staff cannot take a payment with the Online method', function () {
    $setup = curlecSetup();
    $hold = curlecHold($setup);
    curlecOrder($hold);
    postCurlecWebhook(curlecOrderPaid())->assertOk();
    $reservation = Reservation::query()->with('units')->sole();

    $result = app(RecordPayment::class)($setup['user'], $reservation, null, $reservation->units->first(), new NewPaymentData(
        paymentMethodId: $setup['online']->id,
        purpose: PaymentPurpose::Advance,
        amount: '10.00',
        reference: null,
        idempotencyKey: 'a-key',
    ));

    expect($result->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE)
        ->and(collect(app(ReservationFormOptions::class)->payments($setup['property'], $setup['user'])->methods)->pluck('id'))->not->toContain($setup['online']->id);
});

test('a new property gets the active system Online method', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    $profile = CountryProfiles::for('MY');
    app(SeedTransactionCodes::class)($property, $profile);
    app(SeedPaymentMethods::class)($property, $profile);

    expect($property->paymentMethods()->where('type', PaymentMethodType::Online)->sole())
        ->active->toBeTrue()
        ->requires_reference->toBeFalse();
});

test('the cash-up leaves out Online money, and the method totals include it', function () {
    $setup = curlecSetup();
    curlecOrder(curlecHold($setup));
    postCurlecWebhook(curlecOrderPaid())->assertOk();
    $date = Payment::query()->with('folioLine')->sole()->folioLine->posting_date->toDateString();
    $url = "/p/{$setup['property']->code}/reports/payments?from={$date}&to={$date}";

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn ($page) => $page
        ->where('breakdown.rows.0.0', 'Online'));

    $this->actingAs($setup['user'])->get($url.'&group=staff')->assertInertia(fn ($page) => $page
        ->where('breakdown.rows', [])
        ->where('breakdown.total.2', '0'));
});
