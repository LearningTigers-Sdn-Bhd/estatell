<?php

use App\Actions\Events\CancelEvent;
use App\Actions\Events\ConfirmEvent;
use App\Actions\Events\UpdateEvent;
use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\CancelFacilityBooking;
use App\Actions\Facilities\UpdateFacilityBooking;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\FacilityRateBasis;
use App\Enums\PermissionEnum;
use App\Features\Facilities;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\FacilitySession;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * A booking on 20 Oct 2026 (the default event day), 18:00–23:00 for one, at the list price.
 */
function bookingInput(Facility $facility, array $overrides = []): FacilityBookingInputData
{
    return new FacilityBookingInputData(...[
        'facilityId' => $facility->id,
        'bookingDate' => '2026-10-20',
        'startsAt' => '18:00',
        'endsAt' => '23:00',
        'quantity' => 1,
        'unitAmount' => null,
        'notes' => null,
        ...$overrides,
    ]);
}

function hallFor(Property $property, array $attributes = []): Facility
{
    return Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall', ...$attributes]);
}

test('a Private facility takes no overlapping Confirmed booking, and touching times do not overlap', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    [$wedding] = makeEvent($property, $owner);
    [$meeting] = makeEvent($property, $owner, ['name' => 'Sabah Tourism meeting']);

    $first = app(BookFacility::class)->forEvent($owner, $wedding, bookingInput($hall));
    $clash = app(BookFacility::class)->forEvent($owner, $meeting, bookingInput($hall, ['startsAt' => '20:00', 'endsAt' => '22:00']));
    $before = app(BookFacility::class)->forEvent($owner, $meeting, bookingInput($hall, ['startsAt' => '09:00', 'endsAt' => '18:00']));

    expect($first->booking->status)->toBe(FacilityBookingStatus::Confirmed)
        ->and($first->booking->unit_amount)->toBe('1500.00')
        ->and($clash->refusal)->toBe('facility_clash')
        ->and($clash->facilityName)->toBe('Riverside Hall')
        ->and($before->isRefused())->toBeFalse()
        ->and(FacilityBooking::query()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'facility_bookings.booked')->count())->toBe(2);
});

test('a Shared facility takes bookings while the quantity at every moment stays within its capacity', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $kayaks = Facility::factory()->shared()->create(['property_id' => $property->id, 'capacity' => 20]);
    [$event] = makeEvent($property, $owner);
    $book = fn (string $from, string $to, int $quantity) => app(BookFacility::class)->forEvent($owner, $event, bookingInput($kayaks, ['startsAt' => $from, 'endsAt' => $to, 'quantity' => $quantity]));

    expect($book('09:00', '11:00', 12)->isRefused())->toBeFalse()
        ->and($book('11:00', '13:00', 20)->isRefused())->toBeFalse()
        ->and($book('10:00', '12:00', 9)->refusal)->toBe('facility_full')
        ->and($book('10:00', '12:00', 8)->refusal)->toBe('facility_full')
        ->and($book('10:00', '10:30', 8)->isRefused())->toBeFalse();
});

test('Tentative bookings never block, and confirming their event runs the clash check', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    [$held] = makeEvent($property, $owner, ['status' => EventStatus::Tentative]);
    [$wedding] = makeEvent($property, $owner);

    $hold = app(BookFacility::class)->forEvent($owner, $held, bookingInput($hall));
    $taken = app(BookFacility::class)->forEvent($owner, $wedding, bookingInput($hall));
    $confirm = app(ConfirmEvent::class)($owner, $held);

    expect($hold->booking->status)->toBe(FacilityBookingStatus::Tentative)
        ->and($taken->isRefused())->toBeFalse()
        ->and($confirm->refusal)->toBe('facility_clash')
        ->and($confirm->facilityName)->toBe('Riverside Hall')
        ->and($held->refresh()->status)->toBe(EventStatus::Tentative)
        ->and($hold->booking->refresh()->status)->toBe(FacilityBookingStatus::Tentative)
        ->and(Activity::query()->where('event', 'events.confirmed')->exists())->toBeFalse();
});

test('confirming an event confirms its bookings and catches two of its own that clash', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    $kayaks = Facility::factory()->shared()->create(['property_id' => $property->id, 'capacity' => 10]);
    [$event] = makeEvent($property, $owner, ['status' => EventStatus::Tentative]);
    [$other] = makeEvent($property, $owner, ['status' => EventStatus::Tentative]);

    $booking = app(BookFacility::class)->forEvent($owner, $event, bookingInput($hall))->booking;
    app(BookFacility::class)->forEvent($owner, $event, bookingInput($kayaks, ['quantity' => 6]));
    app(BookFacility::class)->forEvent($owner, $other, bookingInput($kayaks, ['quantity' => 6]));

    expect(app(ConfirmEvent::class)($owner, $event)->isRefused())->toBeFalse()
        ->and($booking->refresh()->status)->toBe(FacilityBookingStatus::Confirmed)
        ->and(app(ConfirmEvent::class)($owner, $other)->refusal)->toBe('facility_full');
});

test('booking refuses a turned off facility, a date outside the event, a closed event and a time that is not a session', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    $off = hallFor($property, ['name' => 'Old hall', 'active' => false]);
    $walk = Facility::factory()->shared()->create(['property_id' => $property->id]);
    FacilitySession::factory()->create(['property_id' => $property->id, 'facility_id' => $walk->id, 'starts_at' => '20:00', 'ends_at' => '22:00']);
    [$event] = makeEvent($property, $owner);
    [$cancelled] = makeEvent($property, $owner);
    app(CancelEvent::class)($owner, $cancelled);
    $book = app(BookFacility::class);

    expect($book->forEvent($owner, $event, bookingInput($off))->refusal)->toBe('facility_inactive')
        ->and($book->forEvent($owner, $event, bookingInput($hall, ['bookingDate' => '2026-10-21']))->refusal)->toBe('booking_outside_event')
        ->and($book->forEvent($owner, $cancelled, bookingInput($hall))->refusal)->toBe('event_not_open')
        ->and($book->forEvent($owner, $event, bookingInput($walk, ['startsAt' => '19:00', 'endsAt' => '21:00']))->refusal)->toBe('facility_session_not_found')
        ->and($book->forEvent($owner, $event, bookingInput($walk, ['startsAt' => '20:00', 'endsAt' => '22:00']))->isRefused())->toBeFalse()
        ->and(FacilityBooking::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'facility_bookings.booked')->count())->toBe(1);
});

test('a changed price is kept and logged, and a facility charged by hand saves no price', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    $byHand = hallFor($property, ['name' => 'BBQ pit']);
    $byHand->chargeItem->update(['default_amount' => null]);
    [$event] = makeEvent($property, $owner);

    $negotiated = app(BookFacility::class)->forEvent($owner, $event, bookingInput($hall, ['unitAmount' => '1200.00']))->booking;
    $manual = app(BookFacility::class)->forEvent($owner, $event, bookingInput($byHand, ['unitAmount' => '99.00']))->booking;

    expect($negotiated->unit_amount)->toBe('1200.00')
        ->and($manual->unit_amount)->toBeNull()
        ->and(Activity::query()->where('event', 'facility_bookings.price_changed')->sole()->properties->only(['list_price', 'price'])->all())
        ->toBe(['list_price' => '1500.00', 'price' => '1200.00']);
});

test('editing a booking checks it again without counting itself, and a cancelled booking cannot be edited', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    [$event] = makeEvent($property, $owner);
    [$other] = makeEvent($property, $owner);
    $booking = app(BookFacility::class)->forEvent($owner, $event, bookingInput($hall))->booking;
    app(BookFacility::class)->forEvent($owner, $other, bookingInput($hall, ['startsAt' => '09:00', 'endsAt' => '12:00']));

    $moved = app(UpdateFacilityBooking::class)($owner, $booking, bookingInput($hall, ['startsAt' => '17:00', 'endsAt' => '23:30']));
    $clash = app(UpdateFacilityBooking::class)($owner, $booking, bookingInput($hall, ['startsAt' => '11:00', 'endsAt' => '14:00']));
    app(CancelFacilityBooking::class)($owner, $booking);

    expect($moved->isRefused())->toBeFalse()
        ->and($booking->refresh()->starts_at)->toBe('17:00:00')
        ->and($clash->refusal)->toBe('facility_clash')
        ->and(app(UpdateFacilityBooking::class)($owner, $booking, bookingInput($hall))->refusal)->toBe('facility_booking_cancelled')
        ->and(app(CancelFacilityBooking::class)($owner, $booking))->toBeFalse();
});

test('cancelling an event cancels its bookings, and new event dates must keep its bookings inside', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    [$event] = makeEvent($property, $owner, ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-21']);
    $booking = app(BookFacility::class)->forEvent($owner, $event, bookingInput($hall, ['bookingDate' => '2026-10-21']))->booking;
    $dates = fn (string $endsOn) => ['name' => $event->name, 'payer_guest_id' => $event->payer_guest_id, 'payer_company_id' => null, 'starts_on' => '2026-10-20', 'ends_on' => $endsOn];

    expect(app(UpdateEvent::class)($owner, $event, $dates('2026-10-20'))->refusal)->toBe('booking_outside_event')
        ->and($event->refresh()->ends_on->toDateString())->toBe('2026-10-21');

    app(CancelEvent::class)($owner, $event);

    expect($booking->refresh()->status)->toBe(FacilityBookingStatus::Cancelled)
        ->and($booking->cancelled_by)->toBe($owner->id)
        ->and(app(UpdateEvent::class)($owner, $event->refresh(), $dates('2026-10-20'))->refusal)->toBe('event_not_open');
});

test('an hourly price counts the booked hours rounded up', function () {
    expect(FacilityRateBasis::Hour->quantity('09:00', '11:30', 4))->toBe(3)
        ->and(FacilityRateBasis::Person->quantity('09:00', '11:30', 4))->toBe(4)
        ->and(FacilityRateBasis::Day->quantity('09:00', '11:30', 4))->toBe(1);
});

test('the booking routes need the feature and facilities.book, and stay inside the property', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    [$event] = makeEvent($property, $owner);
    $booking = app(BookFacility::class)->forEvent($owner, $event, bookingInput($hall))->booking;
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::EventsView])->create();
    ['property' => $other, 'owner' => $otherOwner] = eventSetup();

    $this->actingAs($viewer)->post(route('facility-bookings.cancel', [$property->code, $booking]))->assertForbidden();
    $this->actingAs($otherOwner)->post(route('facility-bookings.cancel', [$other->code, $booking]))->assertNotFound();

    Feature::for($property)->deactivate(Facilities::class);

    $this->actingAs($owner)->post(route('facility-bookings.cancel', [$property->code, $booking]))->assertNotFound();

    expect($booking->refresh()->status)->toBe(FacilityBookingStatus::Confirmed);
});

test('staff book from the Sheet, and a clash comes back on the time field', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    [$event] = makeEvent($property, $owner);
    $payload = ['event_id' => $event->id, 'facility_id' => $hall->id, 'booking_date' => '2026-10-20', 'starts_at' => '18:00', 'ends_at' => '23:00', 'quantity' => 150, 'unit_amount' => '1200.00', 'notes' => 'Round tables'];

    $this->actingAs($owner)->post(route('facility-bookings.store', $property->code), $payload)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Riverside Hall booked.']);

    $this->actingAs($owner)->post(route('facility-bookings.store', $property->code), $payload)
        ->assertSessionHasErrors(['starts_at' => 'Riverside Hall is already booked at that time.']);

    $this->actingAs($owner)->post(route('facility-bookings.store', $property->code), [...$payload, 'ends_at' => '17:00'])
        ->assertSessionHasErrors(['ends_at' => 'The end time must be after the start time. A booking cannot cross midnight.']);

    expect(FacilityBooking::query()->sole()->notes)->toBe('Round tables');
});

test('the quote gives the price with tax, the clash, a Tentative hold and the places left in each session', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    $tax = Tax::factory()->for($property)->create(['name' => 'SST', 'rate' => '8.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($hall->chargeItem->transaction_code_id);
    $walk = Facility::factory()->shared()->create(['property_id' => $property->id, 'capacity' => 20]);
    FacilitySession::factory()->create(['property_id' => $property->id, 'facility_id' => $walk->id, 'starts_at' => '20:00', 'ends_at' => '22:00']);
    [$held] = makeEvent($property, $owner, ['name' => 'Sabah Tourism meeting', 'status' => EventStatus::Tentative]);
    [$event] = makeEvent($property, $owner);
    app(BookFacility::class)->forEvent($owner, $held, bookingInput($hall, ['startsAt' => '09:00', 'endsAt' => '19:00']));
    app(BookFacility::class)->forEvent($owner, $event, bookingInput($walk, ['startsAt' => '20:00', 'endsAt' => '22:00', 'quantity' => 12]));
    $quote = fn (array $payload) => $this->actingAs($owner)->postJson(route('facility-bookings.quote', $property->code), [
        'event_id' => $event->id, 'booking_date' => '2026-10-20', 'quantity' => 1, ...$payload,
    ]);

    $quote(['facility_id' => $hall->id, 'starts_at' => '18:00', 'ends_at' => '23:00', 'unit_amount' => '1200.00'])
        ->assertOk()
        ->assertJsonPath('amount', '1200.00')
        ->assertJsonPath('taxes.0.name', 'SST')
        ->assertJsonPath('taxes.0.amount', '96.00')
        ->assertJsonPath('total', '1296.00')
        ->assertJsonPath('refusal', null)
        ->assertJsonPath('warning', 'Riverside Hall is held 09:00–19:00 (Sabah Tourism meeting, Tentative).');

    $quote(['facility_id' => $walk->id, 'starts_at' => '', 'ends_at' => ''])
        ->assertJsonPath('amount', null)
        ->assertJsonPath('sessions.0.left', 8);

    $quote(['facility_id' => $walk->id, 'starts_at' => '20:00', 'ends_at' => '22:00', 'quantity' => 9])
        ->assertJsonPath('refusal', $walk->name.' is full at that time.');
});

test('the Bookings tab lists the bookings with the facilities staff can book', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $hall = hallFor($property);
    hallFor($property, ['name' => 'Old hall', 'active' => false]);
    [$event] = makeEvent($property, $owner);
    app(BookFacility::class)->forEvent($owner, $event, bookingInput($hall, ['unitAmount' => '1200.00']));
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::EventsView])->create();

    $this->actingAs($owner)->get(route('events.bookings', [$property->code, $event]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/bookings')
            ->where('canBook', true)
            ->has('facilities', 1)
            ->where('facilities.0.name', 'Riverside Hall')
            ->has('bookings', 1)
            ->where('bookings.0.amount', '1200.00')
            ->where('bookings.0.startsAt', '18:00')
            ->where('bookings.0.canCancel', true));

    $this->actingAs($viewer)->get(route('events.bookings', [$property->code, $event]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canBook', false)
            ->where('facilities', [])
            ->where('bookings.0.canEdit', false));
});
