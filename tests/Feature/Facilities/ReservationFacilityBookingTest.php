<?php

use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\FacilityBookingResult;
use App\Actions\Facilities\UpdateFacilityBooking;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\CancelUnits;
use App\Actions\FrontDesk\MarkNoShow;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioType;
use App\Enums\PermissionEnum;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Features\Facilities;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Reservations\UnitActionPreview;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * The event setup (Facilities on, business date 14 Oct 2026) with fixed cancellation and no-show fees, a reservation
 * with one Confirmed unit arriving today for two nights, its Master folio, and the Riverside Hall.
 *
 * @return array{property: Property, owner: User, reservation: Reservation, unit: ReservationUnit, master: Folio, hall: Facility}
 */
function reservationFacilitySetup(): array
{
    ['property' => $property, 'owner' => $owner] = eventSetup();

    foreach ([SystemTransactionCode::CancellationRevenue, SystemTransactionCode::NoShowRevenue] as $code) {
        TransactionCode::factory()->for($property)->system($code)->create();
        RoomRevenuePolicy::factory()->for($property)->fixed('0.00')->forCode($code, PolicyScope::WholeStay)->create();
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $master = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id, 'type' => FolioType::Master]);
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall']);

    return compact('property', 'owner', 'reservation', 'unit', 'master', 'hall');
}

/**
 * A booking on 15 Oct 2026, 19:00–22:00 for one, charged to `$chargeTo`.
 */
function reservationBookingInput(Facility $facility, string $chargeTo, array $overrides = []): FacilityBookingInputData
{
    return new FacilityBookingInputData(...[
        'facilityId' => $facility->id,
        'bookingDate' => '2026-10-15',
        'startsAt' => '19:00',
        'endsAt' => '22:00',
        'quantity' => 1,
        'unitAmount' => null,
        'notes' => null,
        'chargeTo' => $chargeTo,
        ...$overrides,
    ]);
}

test('a reservation books a facility, Confirmed, on the folio picked in Charge to, and logs it on the reservation', function () {
    ['owner' => $owner, 'reservation' => $reservation, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();

    $result = app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id));

    expect($result->isRefused())->toBeFalse()
        ->and($result->booking->reservation_id)->toBe($reservation->id)
        ->and($result->booking->event_id)->toBeNull()
        ->and($result->booking->folio_id)->toBe($master->id)
        ->and($result->booking->status)->toBe(FacilityBookingStatus::Confirmed)
        ->and($result->booking->unit_amount)->toBe('1500.00')
        ->and(Activity::query()->where('event', 'facility_bookings.booked')->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)->exists())->toBeTrue();
});

test('Charge to a unit with no folio yet creates its Unit folio on save', function () {
    ['owner' => $owner, 'reservation' => $reservation, 'unit' => $unit, 'hall' => $hall] = reservationFacilitySetup();

    $result = app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'u'.$unit->id));
    $folio = Folio::query()->findOrFail($result->booking->folio_id);

    expect($folio->type)->toBe(FolioType::Unit)
        ->and($folio->reservation_unit_id)->toBe($unit->id);
});

test('a reservation booking is refused, writing nothing', function (Closure $arrange, string $refusal) {
    $setup = reservationFacilitySetup();
    $chargeTo = $arrange($setup);

    $result = app(BookFacility::class)->forReservation($setup['owner'], $setup['reservation'], reservationBookingInput($setup['hall'], $chargeTo));

    expect($result->refusal)->toBe($refusal)
        ->and(FacilityBooking::query()->where('reservation_id', $setup['reservation']->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'facility_bookings.booked')->exists())->toBeFalse();
})->with([
    'another reservation\'s folio' => [fn (array $setup): string => 'f'.Folio::factory()->create(['property_id' => $setup['property']->id])->id, FacilityBookingResult::REFUSAL_FOLIO_NOT_OF_OWNER],
    'a closed folio' => [function (array $setup): string {
        $setup['master']->update(['status' => 'closed']);

        return 'f'.$setup['master']->id;
    }, FacilityBookingResult::REFUSAL_FOLIO_NOT_OPEN],
    'no live unit' => [function (array $setup): string {
        $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save();

        return 'f'.$setup['master']->id;
    }, FacilityBookingResult::REFUSAL_RESERVATION_NOT_LIVE],
    'a clash with another reservation\'s booking' => [function (array $setup): string {
        $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);
        FacilityBooking::factory()->create([
            'property_id' => $setup['property']->id,
            'facility_id' => $setup['hall']->id,
            'event_id' => null,
            'reservation_id' => $other->id,
            'folio_id' => Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => $other->id])->id,
            'booking_date' => '2026-10-15',
            'starts_at' => '20:00',
            'ends_at' => '23:00',
            'status' => FacilityBookingStatus::Confirmed,
        ]);

        return 'f'.$setup['master']->id;
    }, FacilityBookingResult::REFUSAL_CLASH],
]);

test('editing a reservation booking can move it to another folio of the reservation', function () {
    ['owner' => $owner, 'property' => $property, 'reservation' => $reservation, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();
    $booking = app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id))->booking;
    $thirdParty = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id, 'type' => FolioType::ThirdParty, 'payer_guest_id' => Guest::factory()->create(['property_id' => $property->id])->id]);

    $moved = app(UpdateFacilityBooking::class)($owner, $booking, reservationBookingInput($hall, 'f'.$thirdParty->id));
    $refused = app(UpdateFacilityBooking::class)($owner, $booking, reservationBookingInput($hall, 'f'.Folio::factory()->create(['property_id' => $property->id])->id));

    expect($moved->booking->folio_id)->toBe($thirdParty->id)
        ->and($refused->refusal)->toBe(FacilityBookingResult::REFUSAL_FOLIO_NOT_OF_OWNER)
        ->and($booking->fresh()->folio_id)->toBe($thirdParty->id);
});

test('cancelling or no-showing the last live unit cancels the facility bookings', function (string $action) {
    ['owner' => $owner, 'reservation' => $reservation, 'unit' => $unit, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();
    $booking = app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id))->booking;

    $result = app($action)($owner, $unit);

    expect($result->isRefused())->toBeFalse()
        ->and($booking->fresh()->status)->toBe(FacilityBookingStatus::Cancelled)
        ->and($booking->fresh()->cancelled_by)->toBe($owner->id)
        ->and(Activity::query()->where('event', 'facility_bookings.cancelled')->where('subject_id', $reservation->id)->exists())->toBeTrue();
})->with([
    'cancel' => [CancelUnit::class],
    'no-show' => [MarkNoShow::class],
]);

test('cancelling one of two live units keeps the facility bookings', function () {
    ['owner' => $owner, 'reservation' => $reservation, 'unit' => $unit, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();
    ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $booking = app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id))->booking;

    app(CancelUnit::class)($owner, $unit);

    expect($booking->fresh()->status)->toBe(FacilityBookingStatus::Confirmed);
});

test('a whole-reservation cancel through CancelUnits, as the booking engine and channels do, cancels the bookings', function () {
    ['owner' => $owner, 'reservation' => $reservation, 'unit' => $unit, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();
    $second = ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $booking = app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id))->booking;

    $result = app(CancelUnits::class)(null, $reservation, [$unit->id, $second->id]);

    expect($result->isRefused())->toBeFalse()
        ->and($booking->fresh()->status)->toBe(FacilityBookingStatus::Cancelled)
        ->and($booking->fresh()->cancelled_by)->toBeNull();
});

test('the Cancel unit form says how many facility bookings the last live unit also cancels', function () {
    ['owner' => $owner, 'reservation' => $reservation, 'unit' => $unit, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();
    app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id));

    $last = app(UnitActionPreview::class)->fee($unit, SystemTransactionCode::CancellationRevenue);
    ReservationUnit::factory()->for($reservation)->create();
    $notLast = app(UnitActionPreview::class)->fee($unit, SystemTransactionCode::CancellationRevenue);

    expect($last->facilityBookingsToCancel)->toBe(1)
        ->and($notLast->facilityBookingsToCancel)->toBe(0);
});

test('the Facilities tab lists the bookings with Charge to, and is not found while the feature is off', function () {
    ['property' => $property, 'owner' => $owner, 'reservation' => $reservation, 'unit' => $unit, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();
    app(BookFacility::class)->forReservation($owner, $reservation, reservationBookingInput($hall, 'f'.$master->id));

    $this->actingAs($owner)
        ->get(route('reservations.facilities', ['property' => $property, 'reservation' => $reservation]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/show')
            ->where('tab', 'facilities')
            ->where('hasFacilities', true)
            ->where('canBookFacility', true)
            ->has('facilityBookings', 1)
            ->where('facilityBookings.0.chargeTo', 'f'.$master->id)
            ->where('chargeToOptions.0.value', 'f'.$master->id)
            ->where('chargeToOptions.1.value', 'u'.$unit->id)
            ->where('defaultChargeTo.'.$hall->id, 'u'.$unit->id)
            ->where('facilityDefaultDate', '2026-10-14'));

    Feature::for($property)->deactivate(Facilities::class);

    $this->actingAs($owner)
        ->get(route('reservations.facilities', ['property' => $property, 'reservation' => $reservation]))
        ->assertNotFound();
});

test('booking from the Sheet needs facilities.book, and the payer step creates a third-party folio for Charge to', function () {
    ['property' => $property, 'owner' => $owner, 'reservation' => $reservation, 'hall' => $hall] = reservationFacilitySetup();
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($viewer)
        ->post(route('facility-bookings.store', ['property' => $property]), ['reservation_id' => $reservation->id, 'facility_id' => $hall->id, 'booking_date' => '2026-10-15', 'starts_at' => '19:00', 'ends_at' => '22:00', 'quantity' => 1, 'charge_to' => 'u1'])
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('facility-bookings.folios.store', ['property' => $property, 'reservation' => $reservation]), ['payer_guest_id' => $guest->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $folio = Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::ThirdParty)->sole();

    $this->actingAs($owner)
        ->post(route('facility-bookings.store', ['property' => $property]), ['reservation_id' => $reservation->id, 'facility_id' => $hall->id, 'booking_date' => '2026-10-15', 'starts_at' => '19:00', 'ends_at' => '22:00', 'quantity' => 1, 'charge_to' => 'f'.$folio->id])
        ->assertSessionHasNoErrors();

    expect(FacilityBooking::query()->sole()->folio_id)->toBe($folio->id);
});

test('another property\'s reservation is not found for the payer step', function () {
    ['owner' => $owner, 'property' => $property] = reservationFacilitySetup();
    $other = Reservation::factory()->create();

    $this->actingAs($owner)
        ->post(route('facility-bookings.folios.store', ['property' => $property, 'reservation' => $other]), ['payer_guest_id' => Guest::factory()->create(['property_id' => $property->id])->id])
        ->assertNotFound();
});

test('the quote prices a reservation booking', function () {
    ['property' => $property, 'owner' => $owner, 'reservation' => $reservation, 'master' => $master, 'hall' => $hall] = reservationFacilitySetup();

    $this->actingAs($owner)
        ->postJson(route('facility-bookings.quote', ['property' => $property]), ['reservation_id' => $reservation->id, 'facility_id' => $hall->id, 'booking_date' => '2026-10-15', 'starts_at' => '19:00', 'ends_at' => '22:00', 'quantity' => 1, 'charge_to' => 'f'.$master->id])
        ->assertOk()
        ->assertJsonPath('amount', '1500.00')
        ->assertJsonPath('refusal', null);
});
