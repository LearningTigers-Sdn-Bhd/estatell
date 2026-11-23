<?php

use App\Actions\Events\CancelEvent;
use App\Actions\Facilities\PostFacilityBooking;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Enums\FacilityRateBasis;
use App\Enums\PermissionEnum;
use App\Models\Company;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use App\Services\Events\EventBookingSummary;
use App\Services\Reservations\BookingSummaryDocument;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * The event setup with an event on 14–15 Oct 2026 (the business date is 14 Oct), its folio, the Riverside Hall
 * (booked by item) and the Jungle dinner (booked by person, no tax).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{property: Property, owner: User, event: Event, folio: Folio, hall: Facility, dinner: Facility}
 */
function eventSummarySetup(array $attributes = []): array
{
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner, ['name' => 'Lim–Tan wedding', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-15', ...$attributes]);
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall']);
    $dinner = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Jungle dinner', 'booked_by' => FacilityBookedBy::Person, 'rate_basis' => FacilityRateBasis::Each, 'capacity' => 100]);

    return compact('property', 'owner', 'event', 'folio', 'hall', 'dinner');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function eventSummaryBooking(array $setup, Facility $facility, array $attributes = []): FacilityBooking
{
    return FacilityBooking::factory()->create([
        'event_id' => $setup['event']->id,
        'property_id' => $setup['property']->id,
        'facility_id' => $facility->id,
        'folio_id' => $setup['folio']->id,
        'booking_date' => '2026-10-14',
        ...$attributes,
    ]);
}

function eventSummary(array $setup, bool $prices = true): BookingSummaryDocument
{
    return app(EventBookingSummary::class)->for($setup['property']->fresh(), $setup['event']->fresh(), $prices);
}

test('the summary lists the facilities and charges posted and scheduled bookings, less the payments', function () {
    $setup = eventSummarySetup();
    $hall = eventSummaryBooking($setup, $setup['hall'], ['unit_amount' => '5000.00']);
    eventSummaryBooking($setup, $setup['dinner'], ['booking_date' => '2026-10-15', 'starts_at' => '19:00', 'ends_at' => '21:00', 'quantity' => 80, 'unit_amount' => '50.00']);
    eventSummaryBooking($setup, $setup['dinner'], ['booking_date' => '2026-10-15', 'quantity' => 10, 'status' => FacilityBookingStatus::Cancelled]);
    app(PostFacilityBooking::class)($setup['owner'], $hall);
    Payment::factory()->create([
        'folio_id' => $setup['folio']->id,
        'payment_method_id' => PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Bank transfer'])->id,
        'amount' => '2000.00',
        'receipt_number' => 'R-1002',
    ]);

    $summary = eventSummary($setup);
    $rows = collect($summary->lines->groups)->flatMap(fn ($group): array => $group->rows);

    expect($summary->reference)->toBe($setup['event']->number)
        ->and($summary->roomsHeading)->toBe('Facilities')
        ->and($summary->rooms)->toBe([
            '14 Oct · Riverside Hall · 18:00–23:00 · 1 item',
            '15 Oct · Jungle dinner · 19:00–21:00 · 80 people',
        ])
        ->and(collect($summary->strip)->pluck('value', 'label')->all())->toMatchArray(['Event' => 'Lim–Tan wedding', 'Dates' => '14–15 Oct 2026'])
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('amount')->all())->toBe(['5000.00', '4000.00'])
        ->and($summary->lines->total)->toBe('9000.00')
        ->and($summary->paid)->toBe('2000.00')
        ->and($summary->balanceDue)->toBe('7000.00')
        ->and($summary->cancellationPolicy)->toBe([])
        ->and($summary->stamp)->toBeNull();
});

test('a cancelled event is stamped and a tentative booking says so', function () {
    $setup = eventSummarySetup();
    eventSummaryBooking($setup, $setup['hall'], ['status' => FacilityBookingStatus::Tentative]);

    expect(eventSummary($setup)->rooms)->toBe(['14 Oct · Riverside Hall · 18:00–23:00 · 1 item · Tentative']);

    app(CancelEvent::class)($setup['owner'], $setup['event']);

    expect(eventSummary($setup)->stamp)->toBe('CANCELLED');
});

test('a company payer can print without prices, and a guest payer always gets prices', function () {
    $guest = eventSummarySetup();
    $company = eventSummarySetup();
    $company['event']->forceFill(['payer_guest_id' => null, 'payer_company_id' => Company::factory()->for($company['property'])->create(['name' => 'Acme Sdn Bhd'])->id])->save();
    eventSummaryBooking($company, $company['hall']);

    $withoutPrices = eventSummary($company, false);

    expect($withoutPrices->showPrices)->toBeFalse()
        ->and($withoutPrices->lines)->toBeNull()
        ->and($withoutPrices->balanceDue)->toBeNull()
        ->and($withoutPrices->rooms)->toHaveCount(1)
        ->and($withoutPrices->strip[0]['value'])->toBe('Acme Sdn Bhd')
        ->and(eventSummary($guest, false)->showPrices)->toBeTrue();
});

test('the PDF streams inline under the event number with events.view, and another property is not found', function () {
    $setup = eventSummarySetup();
    eventSummaryBooking($setup, $setup['hall']);
    $url = route('events.booking-summary', [$setup['property']->code, $setup['event']]);
    $viewer = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::EventsView])->create();
    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView])->create();
    ['property' => $other, 'owner' => $otherOwner] = eventSetup();
    [$foreign] = makeEvent($other, $otherOwner);

    $response = $this->actingAs($viewer)->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('inline')->toContain('booking-summary-'.$setup['event']->number.'.pdf');

    $this->actingAs($clerk)->get($url)->assertForbidden();
    $this->actingAs($setup['owner'])->get(route('events.booking-summary', [$setup['property']->code, $foreign]))->assertNotFound();
});
