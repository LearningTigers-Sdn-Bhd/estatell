<?php

require_once __DIR__.'/../Support/CurlecSetup.php';

use App\Actions\BookingEngine\PurgeBookingHoldGuestDetails;
use App\Enums\GatewayRefundStatus;
use App\Enums\PolicyRateBasis;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\GatewayRefund;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationCancellationTerm;
use App\Models\RoomRevenuePolicy;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Notifications\OnlineBookingCancelled;
use App\Notifications\OnlineBookingCancelledByGuest;
use App\Services\Folio\FolioBalance;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * A confirmed online booking of RM 200 a room for 2026-10-20, with the given cancellation policy saved on it. The business
 * date is 2026-10-14, six days before arrival. The fee pays SST 8%.
 *
 * @param  array<string, mixed>  $policy
 * @return array<string, mixed>
 */
function cancellableBooking(array $policy = [], int $rooms = 1): array
{
    $setup = curlecSetup();
    $property = $setup['property'];
    $code = TransactionCode::factory()->for($property)->system(SystemTransactionCode::CancellationRevenue)->create();
    $sst = Tax::factory()->for($property)->create(['name' => 'SST 8%', 'rate' => '8.00']);
    $sst->applicableTransactionCodes()->attach($code);
    RoomRevenuePolicy::query()->where('property_id', $property->id)->where('code', SystemTransactionCode::CancellationRevenue)->delete();
    RoomRevenuePolicy::factory()->percentage('50.00', PolicyRateBasis::BookedRate)->create([
        'property_id' => $property->id,
        'applies_to' => 'whole_stay',
        'free_until_days' => 3,
        ...$policy,
    ]);

    $hold = curlecHold($setup, rooms: $rooms);
    curlecOrder($hold);
    postCurlecWebhook(curlecOrderPaid())->assertOk();

    return [...$setup, 'hold' => $hold->refresh(), 'reservation' => Reservation::query()->findOrFail($hold->refresh()->reservation_id)];
}

/**
 * @param  array<string, mixed>  $booking
 */
function cancelOnline(array $booking, string $expectedRefund, string $key = 'cancel-1'): TestResponse
{
    return test()->postJson(
        '/api/booking/v1/bookings/'.$booking['hold']->token.'/cancellation',
        ['expected_refund' => $expectedRefund],
        ['Authorization' => 'Bearer '.BOOKING_API_TOKEN, 'Idempotency-Key' => $key],
    );
}

test('confirming a booking saves the terms, and a later policy change does not touch them', function () {
    $booking = cancellableBooking(['free_until_days' => 10]);

    $terms = ReservationCancellationTerm::query()->where('reservation_id', $booking['reservation']->id)->sole();
    expect($terms->percent)->toBe('50.00')->and($terms->free_until_days)->toBe(10);

    RoomRevenuePolicy::query()->where('property_id', $booking['property']->id)->where('code', SystemTransactionCode::CancellationRevenue)->update(['percent' => '100.00', 'free_until_days' => null]);

    bookingApiGet('bookings/'.$booking['hold']->token.'/cancellation')
        ->assertOk()
        ->assertJson(['can_cancel' => true, 'paid' => '200.00', 'fee' => '100.00', 'tax' => '8.00', 'refund' => '92.00']);
});

test('inside the free window the whole amount comes back', function () {
    $booking = cancellableBooking();

    bookingApiGet('bookings/'.$booking['hold']->token.'/cancellation')
        ->assertOk()
        ->assertJson(['can_cancel' => true, 'fee' => '0.00', 'tax' => '0.00', 'refund' => '200.00']);
});

test('a guest cancels, the room is freed and one refund is asked of Curlec', function () {
    Notification::fake();
    $booking = cancellableBooking(['free_until_days' => 10]);
    $staff = User::factory()->for($booking['property'])->withPermissions(['reservations.manage'])->create();

    cancelOnline($booking, '92.00')->assertOk()->assertJson(['status' => ReservationStatus::Cancelled->value, 'refund' => '92.00']);

    $unit = $booking['reservation']->units()->sole();
    $refund = GatewayRefund::query()->with('parts')->sole();
    expect($unit->status)->toBe(UnitStatus::Cancelled)
        ->and($refund->status)->toBe(GatewayRefundStatus::Pending)
        ->and($refund->amount)->toBe('92.00')
        ->and($refund->requested_by)->toBeNull()
        ->and($refund->parts)->toHaveCount(1)
        ->and($booking['gateway']->refunds)->toHaveCount(1)
        ->and($booking['gateway']->refunds[0]['amount'])->toBe(9200);

    expect(Activity::query()->where('event', 'reservation.cancelled')->sole()->causer_id)->toBeNull();

    Notification::assertSentOnDemand(OnlineBookingCancelled::class, fn (OnlineBookingCancelled $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'aisyah@example.com'
        && $notification->refund === 'MYR 92.00'
        && $notification->fee === 'MYR 108.00'
        && $notification->hasRefund);
    Notification::assertSentTo($staff, OnlineBookingCancelledByGuest::class);
});

test('the refund webhook leaves the folio holding only the fee', function () {
    Notification::fake();
    $booking = cancellableBooking(['free_until_days' => 10]);
    cancelOnline($booking, '92.00')->assertOk();
    $refund = GatewayRefund::query()->sole();

    postCurlecWebhook([
        'event' => 'refund.processed',
        'payload' => [
            'refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_123', 'amount' => 9200, 'status' => 'processed', 'notes' => ['refund_id' => (string) $refund->id]]],
            'payment' => ['entity' => ['id' => 'pay_123', 'captured' => true]],
        ],
    ], 'evt_refund')->assertOk();

    expect($refund->refresh()->status)->toBe(GatewayRefundStatus::Processed)
        ->and(app(FolioBalance::class)->forFolio(Folio::query()->where('reservation_id', $booking['reservation']->id)->sole()))->toBe('0.00');
});

test('the guest email goes to the booker after the hold has emptied its details', function () {
    Notification::fake();
    $booking = cancellableBooking();
    BookingHold::query()->whereKey($booking['hold']->id)->update(['updated_at' => now()->subDays(PurgeBookingHoldGuestDetails::AFTER_DAYS)]);
    app(PurgeBookingHoldGuestDetails::class)();
    expect($booking['hold']->refresh()->guest_email)->toBeNull();

    cancelOnline($booking, '200.00')->assertOk();

    Notification::assertSentOnDemand(OnlineBookingCancelled::class, fn (OnlineBookingCancelled $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'aisyah@example.com');
});

test('a group cancels whole, with one Curlec refund and one part for each room', function () {
    Notification::fake();
    $booking = cancellableBooking(['free_until_days' => 10], rooms: 2);

    cancelOnline($booking, '184.00')->assertOk()->assertJson(['refund' => '184.00']);

    expect($booking['reservation']->units()->where('status', UnitStatus::Cancelled)->count())->toBe(2)
        ->and(GatewayRefund::query()->with('parts')->sole()->parts)->toHaveCount(2)
        ->and($booking['gateway']->refunds)->toHaveCount(1);
});

test('a refund the guest did not see is refused and writes nothing', function () {
    Notification::fake();
    $booking = cancellableBooking(['free_until_days' => 10]);

    cancelOnline($booking, '200.00')->assertStatus(422)->assertJsonPath('error.code', 'refund_changed')->assertJsonPath('error.current_refund', '92.00');

    expect($booking['reservation']->units()->sole()->status)->toBe(UnitStatus::Confirmed)
        ->and(GatewayRefund::query()->count())->toBe(0);
    Notification::assertSentTimes(OnlineBookingCancelled::class, 0);
});

test('online cancel closes on the arrival date', function () {
    $booking = cancellableBooking();
    BusinessDate::query()->where('property_id', $booking['property']->id)->update(['date' => '2026-10-20']);

    bookingApiGet('bookings/'.$booking['hold']->token.'/cancellation')->assertOk()->assertJson(['can_cancel' => false, 'refusal' => 'already_arrived']);
    cancelOnline($booking, '200.00')->assertStatus(422)->assertJsonPath('error.code', 'already_arrived');

    expect($booking['reservation']->units()->sole()->status)->toBe(UnitStatus::Confirmed);
});

test('a fee above the amount paid closes online cancel', function () {
    $booking = cancellableBooking(['charge_mode' => 'fixed_amount', 'amount' => '500.00', 'percent' => null, 'percent_basis' => null, 'free_until_days' => null]);

    bookingApiGet('bookings/'.$booking['hold']->token.'/cancellation')->assertOk()->assertJson(['can_cancel' => false, 'refusal' => 'contact_property']);
    cancelOnline($booking, '0.00')->assertStatus(422)->assertJsonPath('error.code', 'contact_property');

    expect($booking['reservation']->units()->sole()->status)->toBe(UnitStatus::Confirmed);
});

test('a fee equal to the amount paid cancels with no refund row', function () {
    Notification::fake();
    $booking = cancellableBooking(['charge_mode' => 'fixed_amount', 'amount' => '200.00', 'percent' => null, 'percent_basis' => null, 'free_until_days' => null]);
    Tax::query()->where('property_id', $booking['property']->id)->get()->each(fn (Tax $tax) => $tax->applicableTransactionCodes()->detach());

    $refund = bookingApiGet('bookings/'.$booking['hold']->token.'/cancellation')->assertOk()->json('refund');
    cancelOnline($booking, $refund)->assertOk();

    expect($refund)->toBe('0.00')->and(GatewayRefund::query()->count())->toBe(0);
    Notification::assertSentOnDemand(OnlineBookingCancelled::class, fn (OnlineBookingCancelled $notification): bool => ! $notification->hasRefund);
});

test('a repeated key returns the first answer, and a new key finds it already cancelled', function () {
    Notification::fake();
    $booking = cancellableBooking();

    cancelOnline($booking, '200.00')->assertOk();
    cancelOnline($booking, '200.00')->assertOk();
    cancelOnline($booking, '200.00', 'cancel-2')->assertStatus(422)->assertJsonPath('error.code', 'already_cancelled');

    expect(GatewayRefund::query()->count())->toBe(1);
});

test('a guest reads their booking with its terms and refund', function () {
    Notification::fake();
    $booking = cancellableBooking();
    cancelOnline($booking, '200.00')->assertOk();

    bookingApiGet('bookings/'.$booking['hold']->token)
        ->assertOk()
        ->assertJson([
            'reference' => $booking['reservation']->reference,
            'status' => ReservationStatus::Cancelled->value,
            'arrival' => '2026-10-20',
            'guest' => 'Aisyah Rahman',
            'currency' => 'MYR',
            'amount_paid' => '200.00',
            'refund' => ['status' => 'pending', 'amount' => '200.00'],
            'cancellation_terms' => ['free_until_days' => 3],
        ]);
});

test('another client, an unconfirmed hold and a missing token find nothing', function () {
    $booking = cancellableBooking();
    BookingClient::factory()->apiOnly($booking['property'])->withToken('est_live_apionlyclientaaaaaaaaaaaaaaaaaaaaaaaa')->create();
    $held = curlecHold($booking);

    bookingApiGet('bookings/'.$booking['hold']->token, 'est_live_apionlyclientaaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound()->assertJsonPath('error.code', 'booking_not_found');
    bookingApiGet('bookings/'.$held->token)->assertNotFound();
    bookingApiGet('bookings/nothing')->assertNotFound();
    bookingApiGet('bookings/'.$booking['hold']->token, null)->assertUnauthorized();
});

test('a booking still works when the flag goes off', function () {
    Notification::fake();
    $booking = cancellableBooking();
    Feature::for($booking['property'])->deactivate(BookingEngine::class);

    bookingApiGet('bookings/'.$booking['hold']->token)->assertOk();
    cancelOnline($booking, '200.00')->assertOk();
});

test('staff cancelling a room of an online booking refund through Curlec', function () {
    $booking = cancellableBooking(['free_until_days' => 10]);
    $staff = User::factory()->for($booking['property'])->withPermissions(['reservations.manage', 'payment.refund'])->create();
    $key = ['property' => $booking['property']->code, 'reservation' => $booking['reservation']->id, 'unit' => $booking['reservation']->units()->sole()->id];

    $this->actingAs($staff)->postJson(route('reservations.units.cancel-preview', $key))
        ->assertOk()
        ->assertJson(['onlineRefund' => '92.00', 'onlineRefundWaived' => '200.00']);

    $this->actingAs($staff)->post(route('reservations.units.cancel', $key), ['expected_fee' => '100.00'])->assertRedirect()->assertSessionHasNoErrors();

    $refund = GatewayRefund::query()->sole();
    expect($refund->amount)->toBe('92.00')->and($refund->requested_by)->toBe($staff->id);
});

test('staff cancelling a whole online booking get the refund and the preview', function () {
    $booking = cancellableBooking(['free_until_days' => 10], rooms: 2);
    $staff = User::factory()->for($booking['property'])->withPermissions(['reservations.manage', 'payment.refund'])->create();
    $key = ['property' => $booking['property']->code, 'reservation' => $booking['reservation']->id];

    $rows = $this->actingAs($staff)->postJson(route('reservations.cancel-preview', $key))->assertOk()->json('rows');
    expect($rows[0]['onlineRefund'])->toBe('92.00')->and($rows[0]['onlineRefundWaived'])->toBe('200.00');

    $units = $booking['reservation']->units()->pluck('id')->all();
    $this->actingAs($staff)->post(route('reservations.cancel', $key), [
        'unit_ids' => $units,
        'expected_fees' => array_fill_keys($units, '100.00'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(GatewayRefund::query()->with('parts')->sole()->amount)->toBe('184.00');
});

test('staff without the refund permission leave the credit on the folio', function () {
    $booking = cancellableBooking();
    $staff = User::factory()->for($booking['property'])->withPermissions(['reservations.manage'])->create();
    $key = ['property' => $booking['property']->code, 'reservation' => $booking['reservation']->id, 'unit' => $booking['reservation']->units()->sole()->id];

    $this->actingAs($staff)->postJson(route('reservations.units.cancel-preview', $key))->assertOk()->assertJson(['onlineRefund' => '0.00']);
    $this->actingAs($staff)->post(route('reservations.units.cancel', $key), ['expected_fee' => '0.00'])->assertRedirect()->assertSessionHasNoErrors();

    expect($booking['reservation']->units()->sole()->status)->toBe(UnitStatus::Cancelled)
        ->and(GatewayRefund::query()->count())->toBe(0)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(0);
});
