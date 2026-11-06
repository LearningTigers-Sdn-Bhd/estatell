<?php

require_once __DIR__.'/../Support/CurlecSetup.php';

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\FrontDesk\CheckOutAndRefund;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RefundFolioCredit;
use App\Actions\Payments\RefundHeldPayment;
use App\Actions\Payments\RefundPayment;
use App\Actions\Payments\RequestOnlineRefund;
use App\Actions\Payments\ResendWaitingOnlineRefunds;
use App\Actions\Payments\RetryOnlineRefund;
use App\Actions\Payments\VoidPayment;
use App\Enums\BookingHoldStatus;
use App\Enums\FolioStatus;
use App\Enums\GatewayRefundStatus;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\GatewayEvent;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Notifications\LateOnlinePaymentRefunded;
use App\Notifications\OnlinePaymentNeedsAttention;
use App\Notifications\OnlineRefundNeedsAttention;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\Payments\CurlecGateway;
use App\Services\Payments\CurlecRefundRefused;
use App\Services\Payments\CurlecRequestFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A confirmed booking paid online, and its setup.
 *
 * @return array<string, mixed>
 */
function onlineBooking(int $rooms = 1): array
{
    $setup = curlecSetup();
    curlecOrder(curlecHold($setup, rooms: $rooms));
    postCurlecWebhook(curlecOrderPaid())->assertOk();

    $user = User::factory()->for($setup['property'])->withPermissions(['payment.refund', 'payment.refund-other-method', 'reservations.view'])->create();

    return [...$setup, 'staff' => $user, 'payments' => Payment::query()->with('paymentMethod', 'folio')->orderBy('id')->get()];
}

/**
 * @return array<string, mixed>
 */
function curlecRefundEvent(string $event = 'refund.processed', string $refundId = 'rfnd_1', ?int $ourId = null, bool $captured = true, string $paymentId = 'pay_123', ?string $errorDescription = null): array
{
    return [
        'event' => $event,
        'payload' => [
            'refund' => ['entity' => ['id' => $refundId, 'payment_id' => $paymentId, 'amount' => 20000, 'status' => $event === 'refund.failed' ? 'failed' : 'processed', 'notes' => $ourId === null ? [] : ['app' => 'estatell', 'refund' => (string) $ourId]]],
            'payment' => ['entity' => ['id' => $paymentId, 'captured' => $captured, 'error_description' => $errorDescription]],
        ],
    ];
}

function requestRefund(array $booking, string $amount = '200.00', string $key = 'key-1', ?Payment $payment = null): PaymentResult
{
    return app(RefundHeldPayment::class)($booking['staff'], $payment ?? $booking['payments']->first(), $amount, null, $key);
}

// Asking Curlec

test('a refund asks Curlec and posts nothing on the folio until Curlec confirms', function () {
    $booking = onlineBooking();
    $lines = FolioLine::query()->count();

    expect(requestRefund($booking))->payment->toBeNull()->isRefused()->toBeFalse();

    $refund = GatewayRefund::query()->with('parts')->sole();

    expect($refund)
        ->status->toBe(GatewayRefundStatus::Pending)
        ->gateway_refund_id->toBe('rfnd_1')
        ->amount->toBe('200.00')
        ->requested_by->toBe($booking['staff']->id)
        ->and($refund->parts->sole())->payment_id->toBe($booking['payments']->first()->id)->amount->toBe('200.00')
        ->and($booking['gateway']->refunds)->toHaveCount(1)
        ->and($booking['gateway']->refunds[0])->toMatchArray(['paymentId' => 'pay_123', 'amount' => 20000, 'receipt' => "refund-{$refund->id}-1"])
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('the refund sent to Curlec holds no guest details', function () {
    config(['services.curlec.base_url' => 'https://curlec.test/v1', 'services.curlec.key_id' => 'key', 'services.curlec.key_secret' => 'secret']);
    Http::fake(['curlec.test/*' => Http::response(['id' => 'rfnd_real'])]);

    expect(app(CurlecGateway::class)->refund('pay_1', 5000, 'refund-9-1', 9))->toBe('rfnd_real');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://curlec.test/v1/payments/pay_1/refund'
        && $request->data() === ['amount' => 5000, 'receipt' => 'refund-9-1', 'notes' => ['app' => 'estatell', 'refund' => '9']]);
});

test('the gateway tells a problem worth retrying from a final refusal and a repeat', function (int $status, string $description, ?string $exception) {
    config(['services.curlec.base_url' => 'https://curlec.test/v1']);
    Http::fake(['curlec.test/*' => Http::response(['error' => ['description' => $description]], $status)]);

    $call = fn () => app(CurlecGateway::class)->refund('pay_1', 5000, 'refund-9-1', 9);

    $exception === null ? expect($call())->toBeNull() : expect($call)->toThrow($exception);
})->with([
    'low balance' => [400, 'Your account does not have enough balance to carry out the refund operation.', CurlecRequestFailed::class],
    'busy payment' => [400, 'Request failed because another payment operation is in progress.', CurlecRequestFailed::class],
    'server error' => [502, '', CurlecRequestFailed::class],
    'too old' => [400, 'Refund is not supported by the bank because the payment is more than 6 months old', CurlecRefundRefused::class],
    'repeat' => [400, 'Duplicate receipt found for this refund request.', null],
]);

test('a repeat of the key asks Curlec once', function () {
    $booking = onlineBooking();

    requestRefund($booking);
    requestRefund($booking);

    expect(GatewayRefund::query()->count())->toBe(1)->and($booking['gateway']->refunds)->toHaveCount(1);
});

test('a Pending refund counts against what is left, so two requests cannot exceed the payment', function () {
    $booking = onlineBooking();

    expect(requestRefund($booking, '150.00', 'key-1')->isRefused())->toBeFalse()
        ->and(requestRefund($booking, '60.00', 'key-2')->refusal)->toBe(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT)
        ->and(requestRefund($booking, '50.00', 'key-3')->isRefused())->toBeFalse()
        ->and(GatewayRefund::query()->count())->toBe(2);
});

test('a refund on a closed folio is refused and asks Curlec nothing', function () {
    $booking = onlineBooking();
    Folio::query()->whereKey($booking['payments']->first()->folio_id)->update(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $booking['staff']->id]);

    expect(requestRefund($booking)->refusal)->toBe(PaymentResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and($booking['gateway']->refunds)->toBe([]);
});

// Rules for Online payments

test('an Online payment cannot be refunded or voided without Curlec', function () {
    $booking = onlineBooking();
    $payment = $booking['payments']->first();

    expect(app(RefundPayment::class)($booking['staff'], $payment, '10.00', 'x', 'key-1')->refusal)->toBe(PaymentResult::REFUSAL_ONLINE_REFUND_ONLY)
        ->and(app(VoidPayment::class)($booking['staff'], $payment, 'key-2')->refusal)->toBe(PaymentResult::REFUSAL_ONLINE_REFUND_ONLY)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});

test('an Online payment cannot go back by another method, even with the permission', function () {
    $booking = onlineBooking();
    $other = $booking['property']->paymentMethods()->where('id', '!=', $booking['online']->id)->first()?->id ?? $booking['online']->id + 1;

    expect(app(RefundHeldPayment::class)($booking['staff'], $booking['payments']->first(), '10.00', null, 'key-1', methodId: $other, reason: 'Guest asked')->refusal)
        ->toBe(PaymentResult::REFUSAL_ONLINE_REFUND_ONLY)
        ->and(GatewayRefund::query()->count())->toBe(0);
});

test('a hand recorded Online refund needs the Curlec refund ID as its reference', function () {
    $booking = onlineBooking();
    $payment = $booking['payments']->first();

    expect(app(RefundPayment::class)($booking['staff'], $payment, '10.00', null, 'key-1', confirmedByCurlec: true)->refusal)->toBe(PaymentResult::REFUSAL_REFERENCE_REQUIRED)
        ->and(app(RefundPayment::class)($booking['staff'], $payment, '10.00', 'rfnd_dash', 'key-2', confirmedByCurlec: true)->payment?->reference)->toBe('rfnd_dash');
});

// The webhook

test('refund.processed posts the refund line under the person who asked, once', function () {
    $booking = onlineBooking();
    requestRefund($booking, '50.00');

    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r1')->assertOk();
    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r2')->assertOk();

    $line = Payment::query()->whereNotNull('refund_of_id')->sole();

    expect(GatewayRefund::query()->sole()->status)->toBe(GatewayRefundStatus::Processed)
        ->and($line)->amount->toBe('50.00')->reference->toBe('rfnd_1')->received_by->toBe($booking['staff']->id)->refund_of_id->toBe($booking['payments']->first()->id);
});

test('a webhook that beats the answer to the request still finds its row', function () {
    $booking = onlineBooking();
    $booking['gateway']->answersWithId = false;
    requestRefund($booking);
    $refund = GatewayRefund::query()->sole();

    expect($refund->gateway_refund_id)->toBeNull();

    postCurlecWebhook(curlecRefundEvent(refundId: 'rfnd_early', ourId: $refund->id), eventId: 'evt_r1')->assertOk();

    expect($refund->refresh())->status->toBe(GatewayRefundStatus::Processed)->gateway_refund_id->toBe('rfnd_early')
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(1);
});

test('one refund for a group posts one line for each unit', function () {
    $booking = onlineBooking(rooms: 2);
    $parts = $booking['payments']->map(fn (Payment $payment): array => ['payment' => $payment, 'amount' => '200.00'])->all();

    app(RequestOnlineRefund::class)($booking['staff'], GatewayPayment::query()->sole(), $parts, '400.00', 'group-key');

    expect($booking['gateway']->refunds)->toHaveCount(1)->and($booking['gateway']->refunds[0]['amount'])->toBe(40000);

    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r1')->assertOk();

    expect(Payment::query()->whereNotNull('refund_of_id')->pluck('refund_of_id')->sort()->values()->all())->toBe($booking['payments']->pluck('id')->all());
});

test('a line the folio refuses rolls back, keeps the refund Pending and can run again', function () {
    Notification::fake();
    $booking = onlineBooking();
    $admin = User::factory()->admin()->create();
    $folio = $booking['payments']->first()->folio;
    requestRefund($booking);
    $folio->forceFill(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => $booking['staff']->id])->save();

    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r1')->assertOk();

    expect(GatewayRefund::query()->sole()->status)->toBe(GatewayRefundStatus::Pending)
        ->and(GatewayEvent::query()->where('event_id', 'evt_r1')->sole()->processed_at)->toBeNull()
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);

    Notification::assertSentTo($admin, OnlinePaymentNeedsAttention::class);
});

test('refund.failed keeps the credit, alerts staff and admins, and staff can retry', function () {
    Notification::fake();
    $booking = onlineBooking();
    $admin = User::factory()->admin()->create();
    requestRefund($booking);

    postCurlecWebhook(curlecRefundEvent('refund.failed', errorDescription: 'Bank refused'), eventId: 'evt_r1')->assertOk();

    $refund = GatewayRefund::query()->sole();

    expect($refund)->status->toBe(GatewayRefundStatus::Failed)->failure_reason->toBe('Bank refused')
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);

    Notification::assertSentTo($admin, OnlinePaymentNeedsAttention::class);
    Notification::assertSentTo($booking['staff'], OnlineRefundNeedsAttention::class);

    expect(app(RetryOnlineRefund::class)($booking['staff'], $refund)->isRefused())->toBeFalse()
        ->and($refund->refresh())->status->toBe(GatewayRefundStatus::Pending)->attempts->toBe(2)->gateway_refund_id->toBe('rfnd_2')
        ->and($booking['gateway']->refunds[1]['receipt'])->toBe("refund-{$refund->id}-2");
});

test('a failed refund cannot be retried after its money went back another way', function () {
    $booking = onlineBooking();
    requestRefund($booking);
    postCurlecWebhook(curlecRefundEvent('refund.failed'), eventId: 'evt_r1')->assertOk();
    app(RefundPayment::class)($booking['staff'], $booking['payments']->first(), '200.00', 'rfnd_dash', 'by-hand', confirmedByCurlec: true);

    expect(app(RetryOnlineRefund::class)($booking['staff'], GatewayRefund::query()->sole())->refusal)->toBe(PaymentResult::REFUSAL_REFUND_EXCEEDS_PAYMENT);
});

test('a Processed refund cannot be retried', function () {
    $booking = onlineBooking();
    requestRefund($booking, '50.00');
    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r1')->assertOk();

    expect(app(RetryOnlineRefund::class)($booking['staff'], GatewayRefund::query()->sole())->refusal)->toBe(PaymentResult::REFUSAL_NOT_REFUNDABLE);
});

test('Curlec auto-refunds of uncaptured payments and refunds of other payments are ignored', function (bool $captured, string $paymentId) {
    Notification::fake();
    onlineBooking();
    User::factory()->admin()->create();

    postCurlecWebhook(curlecRefundEvent(captured: $captured, paymentId: $paymentId), eventId: 'evt_r1')->assertOk();

    expect(GatewayEvent::query()->where('event_id', 'evt_r1')->sole()->processed_at)->not->toBeNull();
    Notification::assertNotSentTo(User::query()->where('is_admin', true)->get(), OnlinePaymentNeedsAttention::class);
})->with([
    'not captured' => [false, 'pay_123'],
    'another payment' => [true, 'pay_of_someone_else'],
]);

test('a refund made outside estatell alerts admins and posts nothing', function () {
    Notification::fake();
    onlineBooking();
    $admin = User::factory()->admin()->create();

    postCurlecWebhook(curlecRefundEvent(refundId: 'rfnd_dash'), eventId: 'evt_r1')->assertOk();

    expect(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
    Notification::assertSentTo($admin, OnlinePaymentNeedsAttention::class, fn (OnlinePaymentNeedsAttention $notification): bool => str_contains($notification->problem, 'rfnd_dash'));
});

// When a refund cannot run

test('a problem worth retrying stays Pending, alerts admins once and is sent again', function () {
    Notification::fake();
    $booking = onlineBooking();
    $admin = User::factory()->admin()->create();
    $booking['gateway']->failing = true;

    requestRefund($booking);

    $refund = GatewayRefund::query()->sole();
    expect($refund)->status->toBe(GatewayRefundStatus::Pending)->gateway_refund_id->toBeNull()->failure_reason->not->toBeNull();

    expect(app(ResendWaitingOnlineRefunds::class)())->toBe(0);
    $this->travel(16)->minutes();
    expect(app(ResendWaitingOnlineRefunds::class)())->toBe(1);

    Notification::assertSentToTimes($admin, OnlinePaymentNeedsAttention::class, 1);

    $booking['gateway']->failing = false;
    $this->travel(16)->minutes();
    app(ResendWaitingOnlineRefunds::class)();

    expect($refund->refresh())->gateway_refund_id->toBe('rfnd_1')->failure_reason->toBeNull();
    $this->travel(16)->minutes();
    expect(app(ResendWaitingOnlineRefunds::class)())->toBe(0);
});

test('a refund Curlec refuses for good becomes Failed and keeps the credit', function () {
    Notification::fake();
    $booking = onlineBooking();
    $booking['gateway']->refusing = 'The payment is more than 6 months old';

    requestRefund($booking);

    expect(GatewayRefund::query()->sole())->status->toBe(GatewayRefundStatus::Failed)->failure_reason->toBe('The payment is more than 6 months old');
    Notification::assertSentTo($booking['staff'], OnlineRefundNeedsAttention::class);
});

// The folio

test('a folio with a Pending refund cannot close', function () {
    $booking = onlineBooking();
    $folio = $booking['payments']->first()->folio;
    ReservationUnit::query()->sole()->nights()->delete();
    requestRefund($booking);

    expect(app(CloseFolio::class)($booking['staff'], $folio)->refusal)->toBe(FolioStatusResult::REFUSAL_ONLINE_REFUND_PENDING);
});

test('Refund credit sends the credit to Curlec, and a repeat sends nothing more', function () {
    $booking = onlineBooking();
    $folio = $booking['payments']->first()->folio;
    $key = (string) Str::uuid();

    $result = app(RefundFolioCredit::class)($booking['staff'], $folio, '200.00', null, null, $key);
    $repeat = app(RefundFolioCredit::class)($booking['staff'], $folio, '200.00', null, null, $key);

    expect($result->isRefused())->toBeFalse()->and($result->payment)->toBeNull()
        ->and($repeat->isRefused())->toBeFalse()
        ->and(GatewayRefund::query()->count())->toBe(1)
        ->and(app(CheckOutSettlement::class)->settledBalance($folio)->isZero())->toBeTrue();
});

test('the folio page shows the Pending refund, takes it off the amount left, and offers no Void', function () {
    $booking = onlineBooking();
    $payment = $booking['payments']->first();
    $reservation = Reservation::query()->sole();
    $user = User::factory()->for($booking['property'])->withPermissions(['payment.refund', 'reservations.view'])->create();
    requestRefund($booking, '50.00');

    $this->actingAs($user)
        ->get("/p/{$booking['property']->code}/reservations/{$reservation->id}/folio?folio={$payment->folio_id}")
        ->assertInertia(fn ($page) => $page
            ->where('folio.onlineRefunds.0.amount', '50.00')
            ->where('folio.onlineRefunds.0.status.value', 'pending')
            ->where('folio.onlineRefunds.0.canRetry', false)
            ->where('folio.rows', fn ($rows): bool => collect($rows)->firstWhere('paymentId', $payment->id)['refundable'] === '150.00'
                && ! in_array('void', collect($rows)->firstWhere('paymentId', $payment->id)['actions'], true)));
});

test('retry is scoped to the folio and the property, and needs the permission', function () {
    $booking = onlineBooking();
    $payment = $booking['payments']->first();
    $reservation = Reservation::query()->sole();
    requestRefund($booking);
    postCurlecWebhook(curlecRefundEvent('refund.failed'), eventId: 'evt_r1')->assertOk();
    $refund = GatewayRefund::query()->sole();
    $url = "/p/{$booking['property']->code}/reservations/{$reservation->id}/folios/{$payment->folio_id}/online-refunds/{$refund->id}/retry";
    $other = Folio::factory()->create(['property_id' => $booking['property']->id, 'reservation_id' => $reservation->id]);

    $this->actingAs($booking['staff'])->post("/p/{$booking['property']->code}/reservations/{$reservation->id}/folios/{$other->id}/online-refunds/{$refund->id}/retry")->assertNotFound();
    $this->actingAs(User::factory()->for($booking['property'])->withPermissions(['reservations.view'])->create())->post($url)->assertForbidden();

    expect($refund->refresh()->status)->toBe(GatewayRefundStatus::Failed);

    $this->actingAs($booking['staff'])->post($url)->assertRedirect();

    expect($refund->refresh()->status)->toBe(GatewayRefundStatus::Pending);
});

// Check-out

test('check-out goes ahead with Online credit, and the webhook posts the refund and closes the folio', function () {
    $booking = onlineBooking();
    $payment = $booking['payments']->first();
    $unit = ReservationUnit::query()->sole();
    $today = CarbonImmutable::parse((string) BusinessDate::query()->where('property_id', $unit->property_id)->value('date'));
    $unit->nights()->delete();
    $unit->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => $today->subDay(), 'arrival_date' => $today->subDay(), 'departure_date' => $today])->save();
    $key = (string) Str::uuid();

    $result = app(CheckOutAndRefund::class)($booking['staff'], $unit, '200.00', null, $key);

    expect($result->refusal)->toBeNull()
        ->and($unit->refresh()->status)->toBe(UnitStatus::CheckedOut)
        ->and($payment->folio->refresh()->status)->toBe(FolioStatus::Open)
        ->and(GatewayRefund::query()->sole()->status)->toBe(GatewayRefundStatus::Pending);

    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r1')->assertOk();

    expect($payment->folio->refresh())->status->toBe(FolioStatus::Closed)->closed_by->toBe($booking['staff']->id);
});

test('a refused check-out asks Curlec nothing', function () {
    $booking = onlineBooking();
    $unit = ReservationUnit::query()->sole();

    $result = app(CheckOutAndRefund::class)($booking['staff'], $unit, '200.00', null, (string) Str::uuid());

    expect($result->refusal)->not->toBeNull()
        ->and(GatewayRefund::query()->count())->toBe(0)
        ->and($booking['gateway']->refunds)->toBe([]);
});

// Late payments

test('a late payment whose rooms are taken is refunded automatically, and the guest, staff and admins are told once', function () {
    Notification::fake();
    $setup = curlecSetup();
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->for($setup['property'])->withPermissions(['payment.refund'])->create();
    $hold = curlecHold($setup, 'expired');
    curlecOrder($hold);

    foreach ($setup['rooms'] as $room) {
        curlecHold($setup, 'paid');
    }

    postCurlecWebhook(curlecOrderPaid())->assertOk();
    postCurlecWebhook(curlecOrderPaid(), eventId: 'evt_2')->assertOk();

    $refund = GatewayRefund::query()->sole();

    expect($refund)->status->toBe(GatewayRefundStatus::Pending)->amount->toBe('200.00')->requested_by->toBeNull()->gateway_refund_id->toBe('rfnd_1')
        ->and($refund->parts()->count())->toBe(0)
        ->and($setup['gateway']->refunds)->toHaveCount(1)
        ->and($hold->refresh()->status)->toBe(BookingHoldStatus::Expired);

    Notification::assertSentOnDemandTimes(LateOnlinePaymentRefunded::class, 1);
    Notification::assertSentToTimes($staff, OnlineRefundNeedsAttention::class, 1);
    Notification::assertSentToTimes($admin, OnlinePaymentNeedsAttention::class, 1);

    postCurlecWebhook(curlecRefundEvent(), eventId: 'evt_r1')->assertOk();

    expect($refund->refresh()->status)->toBe(GatewayRefundStatus::Processed)
        ->and(Payment::query()->count())->toBe(0);
});
