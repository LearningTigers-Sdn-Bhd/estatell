<?php

use App\Actions\Portal\CreatePortalReservation;
use App\Actions\Portal\RequestPortalCancellation;
use App\Data\Portal\NewPortalBookingData;
use App\Data\Portal\NewPortalGuestData;
use App\Data\Portal\NewPortalLineData;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Portal\PortalBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = $this->signInAsCompanyPortalUser();
    $this->company->update(['portal_cancel_cutoff_days' => 7]);
    $this->link = CompanyUser::query()->where('company_id', $this->company->id)->firstOrFail();

    DB::table('company_rate_plans')->where('company_id', $this->company->id)->update(['in_portal' => true]);
});

/**
 * Book one Deluxe Twin on the Corporate plan for the company, the way the portal form does, through the real Action.
 */
function portalBookingsBook(Company $company, CompanyUser $link, string $arrival, int $nights, string $reference, string $guest): Reservation
{
    $plan = RatePlan::query()->where('property_id', $company->property_id)->where('name', 'Corporate')->firstOrFail();
    $roomType = RoomType::query()->where('property_id', $company->property_id)->where('name', 'Deluxe Twin')->firstOrFail();
    $arrivalDate = CarbonImmutable::parse($arrival);
    $departure = $arrivalDate->addDays($nights);
    $line = new NewPortalLineData($plan->id, $roomType->id, 2);
    $total = app(PortalBookingQuote::class)->lineTotal($company->property, $roomType, $line, $arrivalDate, $departure);

    $result = app(CreatePortalReservation::class)($link->user, $link, new NewPortalBookingData(
        $arrivalDate,
        $departure,
        [$line],
        [new NewPortalGuestData($guest, '+60 12-345 6789')],
        null,
        $reference,
        (string) $total,
    ));

    expect($result->refusal)->toBeNull();

    return $result->reservation;
}

test('the new booking form', function () {
    $page = $this->open(route('portal.bookings.create', $this->property->code), height: 1300)
        ->waitForText('Rooms and beds')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-form', badges: ['Arrival', 'Your reference (optional)', 'Rooms and beds', 'Guests', 'Confirm booking'], badgesFromLeft: 0);
});

test('the bookings list', function () {
    portalBookingsBook($this->company, $this->link, '2026-10-28', 2, 'BT-2026-118', 'Tan Wei Ming');
    portalBookingsBook($this->company, $this->link, '2026-11-12', 3, 'BT-2026-120', 'Aisyah Rahman');

    $page = $this->open(route('portal.bookings.index', $this->property->code))
        ->waitForText('BT-2026-118')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-list', badges: ['New booking', 'Your ref'], badgesFromLeft: 0);
});

test('a booking inside the free cancellation period', function () {
    $reservation = portalBookingsBook($this->company, $this->link, '2026-10-28', 2, 'BT-2026-118', 'Tan Wei Ming');

    $page = $this->open(route('portal.bookings.show', [$this->property->code, $reservation]))
        ->waitForText('Cancel booking')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-detail', badges: ['Edit guests', 'Cancel booking'], badgesFromLeft: 0);

    $page->click('Cancel booking')
        ->waitForText('All rooms of this booking are cancelled with no charge.')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-cancel', frame: '[role="alertdialog"]');
});

test('the edit guests sheet', function () {
    $reservation = portalBookingsBook($this->company, $this->link, '2026-10-28', 2, 'BT-2026-118', 'Tan Wei Ming');

    $page = $this->open(route('portal.bookings.show', [$this->property->code, $reservation]))
        ->waitForText('Edit guests')
        ->click('Edit guests')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-edit-guests', frame: '[role="dialog"]');
});

test('a booking past the cut-off asks the property to cancel', function () {
    $reservation = portalBookingsBook($this->company, $this->link, '2026-10-18', 2, 'BT-2026-121', 'Lim Hui Min');

    $page = $this->open(route('portal.bookings.show', [$this->property->code, $reservation]))
        ->waitForText('Request cancellation')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-request-closed', badges: ['Request cancellation'], badgesFromLeft: 0);

    $page->click('Request cancellation')
        ->waitForText('Free cancellation has ended for this booking.')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-request', frame: '[role="alertdialog"]');
});

test('a booking with a cancellation request', function () {
    $reservation = portalBookingsBook($this->company, $this->link, '2026-10-18', 2, 'BT-2026-121', 'Lim Hui Min');
    $reservation->load('units');
    app(RequestPortalCancellation::class)($this->link->user, $this->link, $reservation);

    $page = $this->open(route('portal.bookings.show', [$this->property->code, $reservation]))
        ->waitForText('You asked the property to cancel this booking')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-requested', badgesFromLeft: 0);
});

test('a portal booking at the front desk', function () {
    $reservation = portalBookingsBook($this->company, $this->link, '2026-10-28', 2, 'BT-2026-118', 'Tan Wei Ming');
    $this->actingAs($this->property->users()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail());

    $page = $this->open(route('reservations.show', [$this->property, $reservation]), height: 1000)
        ->waitForText('BT-2026-118')
        ->wait(1);

    $this->shoot($page, 'portal-bookings-front-desk', badges: ['Company / Travel agent', 'BT-2026-118']);
});
