<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Actions\Folio\CloseSettledUnitFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\FrontDesk\CancelUnits;
use App\Actions\Payments\PaymentResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\DeleteBookingSource;
use App\Actions\Reservations\ReservationResult;
use App\Actions\Reservations\UpdateBookingSource;
use App\Data\Payments\NewPaymentData;
use App\Data\Reports\PaymentRow;
use App\Data\Reservations\BookingSourceOptionData;
use App\Data\Reservations\NewBookingData;
use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PolicyScope;
use App\Enums\ReservationStatus;
use App\Enums\SwatchColor;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\BookingSource;
use App\Models\BusinessDate;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\RoomRevenuePolicy;
use App\Models\TransactionCode;
use App\Services\Payments\PaymentReceipt;
use App\Services\Reservations\BookingSourceOptions;
use Database\Factories\BookingSourceFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 */
function bookWithNoStaff(array $setup, array $overrides = []): BookingResult
{
    return app(CreateBooking::class)(null, $setup['property'], NewBookingData::from([
        'bookingSource' => BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::BookingEngine),
        'units' => [['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-20', 'departureDate' => '2026-10-22', 'adults' => 2]],
        'guests' => [['profile' => ['full_name' => 'Aina Rahman'], 'unitIndex' => 0]],
        ...$overrides,
    ]));
}

/**
 * A booking made with no actor on an open business date, and a Cash method.
 *
 * @return array{property: Property, reservation: Reservation, cash: PaymentMethod}
 */
function noStaffPaymentSetup(): array
{
    $setup = bookingSetup();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id]);

    return [
        'property' => $setup['property'],
        'reservation' => bookWithNoStaff($setup)->reservation,
        'cash' => PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]),
    ];
}

/**
 * @param  array{reservation: Reservation}  $setup
 */
function payWithNoStaff(array $setup, PaymentMethod $method, string $key = 'order-1'): PaymentResult
{
    $reservation = $setup['reservation'];

    return app(RecordPayment::class)(null, $reservation, null, $reservation->units()->firstOrFail(), NewPaymentData::from([
        'paymentMethodId' => $method->id,
        'purpose' => PaymentPurpose::Advance,
        'amount' => '100.00',
        'reference' => null,
        'idempotencyKey' => $key,
    ]));
}

test('a booking with no actor saves the guest, reservation and room, and logs the source with no causer', function () {
    $setup = bookingSetup();

    $result = bookWithNoStaff($setup, ['picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][1]->id]]]);

    $reservation = $result->reservation;
    $unit = $reservation->units()->with('nights')->sole();
    $created = Activity::query()->where('event', 'reservation.created')->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($reservation->created_by)->toBeNull()
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->booker->full_name)->toBe('Aina Rahman')
        ->and($unit->nights->pluck('room_id')->unique()->all())->toBe([$setup['rooms'][1]->id])
        ->and($created->properties->get('booking_source'))->toBe('Booking Engine')
        ->and(Activity::query()->whereNotNull('causer_id')->count())->toBe(0);
});

test('a booking with no actor is refused when the room type is sold out', function () {
    $setup = bookingSetup();
    bookWithNoStaff($setup);
    bookWithNoStaff($setup);

    $result = bookWithNoStaff($setup);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE)
        ->and(Reservation::query()->count())->toBe(2);
});

test('a booking with no actor cannot override a restriction', function () {
    $setup = bookingSetup();
    Rate::query()->where('date', '2026-10-20')->update(['closed_to_arrival' => true]);
    $line = ['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-20', 'departureDate' => '2026-10-22', 'adults' => 2];

    expect(bookWithNoStaff($setup)->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTED)
        ->and(bookWithNoStaff($setup, ['units' => [[...$line, 'restrictionOverrideReason' => 'Guest asked']]])->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED)
        ->and(Reservation::query()->count())->toBe(0);
});

test('units can be cancelled with no actor', function () {
    $setup = bookingSetup();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id]);
    $reservation = bookWithNoStaff($setup)->reservation;
    $unit = $reservation->units()->sole();

    TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::CancellationRevenue)->create();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();

    $result = app(CancelUnits::class)(null, $reservation, [$unit->id]);

    expect($result->isRefused())->toBeFalse()
        ->and($result->totalFee)->toBe('75.00')
        ->and($unit->refresh()->status)->toBe(UnitStatus::Cancelled)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'cancel:%')->sole()->user_id)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['reservation.unit_cancelled', 'reservation.cancelled'])->whereNotNull('causer_id')->count())->toBe(0);
});

test('a payment with no actor posts once, with no receiver or line user', function () {
    $setup = noStaffPaymentSetup();

    $first = payWithNoStaff($setup, $setup['cash']);
    $repeat = payWithNoStaff($setup, $setup['cash']);

    $line = FolioLine::query()->findOrFail($first->payment->folio_line_id);

    expect($first->isRefused())->toBeFalse()
        ->and($first->payment->received_by)->toBeNull()
        ->and($first->payment->status)->toBe(PaymentStatus::Completed)
        ->and($line->user_id)->toBeNull()
        ->and($repeat->payment->id)->toBe($first->payment->id)
        ->and(FolioLine::query()->where('idempotency_key', 'payment:order-1')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'payment.recorded')->sole()->causer_id)->toBeNull();
});

test('a payment with no actor cannot use an Old system method', function () {
    $setup = noStaffPaymentSetup();
    $oldSystem = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::OldSystem, 'requires_reference' => false]);

    expect(payWithNoStaff($setup, $oldSystem)->refusal)->toBe(PaymentResult::REFUSAL_METHOD_NOT_AVAILABLE);
});

test('the receipt and the payments report name the Booking Engine when no staff took the payment', function () {
    $setup = noStaffPaymentSetup();
    $payment = payWithNoStaff($setup, $setup['cash'])->payment;

    $row = PaymentRow::fromPayment($payment, '2026-10-14');

    expect(app(PaymentReceipt::class)->forPayment($payment)->receivedBy)->toBe('Booking Engine')
        ->and($row->staff)->toBe('Booking Engine')
        ->and($row->staff_id)->toBe(0);
});

test('a settled folio of a checked-out unit stays open when there is no actor', function () {
    $setup = noStaffPaymentSetup();
    $unit = $setup['reservation']->units()->firstOrFail();
    $folio = app(CreateFolio::class)($setup['reservation'], $unit);
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()])->save();

    app(CloseSettledUnitFolio::class)(null, $folio);

    expect($folio->refresh()->status)->toBe(FolioStatus::Open);
});

test('the staff booking form neither offers nor accepts the Booking Engine source', function () {
    $setup = bookingSetup();
    $source = BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::BookingEngine);
    $ids = fn (array $options): array => array_map(fn (BookingSourceOptionData $option): int => $option->id, $options);
    $options = app(BookingSourceOptions::class);

    expect($ids($options->forBooking($setup['property'], false, true)))->not->toContain($source->id)
        ->and($ids($options->forBooking($setup['property'], true, true)))->not->toContain($source->id)
        ->and($ids($options->forFilter($setup['property'])))->toContain($source->id);

    $this->actingAs($setup['user'])
        ->from(route('reservations.new', ['property' => $setup['property']->code]))
        ->post(route('reservations.store', ['property' => $setup['property']->code]), [
            'booking_source_id' => $source->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'guests' => [['guest_id' => $setup['guest']->id]],
            'billing_mode' => 'master_pays',
            'lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []]],
        ])
        ->assertSessionHasErrors(['booking_source_id' => 'This booking source is not available. Pick another.']);

    expect(Reservation::query()->count())->toBe(0);
});

test('staff can change the Booking Engine badge but cannot rename, turn off or delete it', function () {
    $setup = bookingSetup();
    $source = BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::BookingEngine);
    $update = app(UpdateBookingSource::class);

    expect($update($setup['user'], $source, ['name' => 'Website']))->toBeFalse()
        ->and($update($setup['user'], $source, ['is_active' => false]))->toBeFalse()
        ->and(app(DeleteBookingSource::class)($setup['user'], $source))->toBeFalse()
        ->and($update($setup['user'], $source, ['badge_icon' => BookingSourceIcon::Star->value, 'badge_letters' => null, 'badge_color' => SwatchColor::Blue->value]))->toBeTrue()
        ->and($source->refresh()->name)->toBe('Booking Engine')
        ->and($source->badge_color)->toBe(SwatchColor::Blue);
});

test('a Booking Engine source must be a system row', function () {
    expect(fn () => BookingSource::factory()->create(['type' => BookingSourceType::BookingEngine, 'is_system' => false]))
        ->toThrow(QueryException::class);
});

test('the backfill gives every property the source and keeps a source that has the name', function () {
    $migration = include database_path('migrations/2026_11_04_222329_allow_bookings_with_no_staff_actor.php');
    $plain = Property::factory()->create();
    $clashing = Property::factory()->create();
    BookingSource::factory()->create(['property_id' => $clashing->id, 'name' => 'Booking engine']);

    $migration->down();
    $migration->up();

    $sources = fn (Property $property) => DB::table('booking_sources')->where('property_id', $property->id)->where('type', 'booking_engine')->get();

    expect($sources($plain)->sole()->name)->toBe('Booking Engine')
        ->and($sources($plain)->sole()->is_system)->toBeTrue()
        ->and($sources($clashing)->sole()->name)->toBe('Booking Engine (Online)')
        ->and(BookingSource::query()->where('property_id', $clashing->id)->where('name', 'Booking engine')->exists())->toBeTrue();
});
