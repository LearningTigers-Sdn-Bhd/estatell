<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\CancelPortalReservation;
use App\Actions\Portal\PortalBookingResult;
use App\Actions\Portal\RequestPortalCancellation;
use App\Actions\Portal\UpdatePortalReservationGuests;
use App\Data\Portal\PortalBookingRowData;
use App\Data\Property\CurrencyData;
use App\Enums\CompanyStatus;
use App\Enums\IdentityDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UpdatePortalGuestsRequest;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\Portal\PortalBookingMessages;
use App\Services\Portal\PortalBookings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The company's bookings at the property: list, detail, guest edits, cancel and cancellation request. A booking is
 * found through the company of the link and the property of the URL, so another company's booking is a 404.
 */
class PortalBookingController extends Controller
{
    /**
     * The bookings list, 20 to a page, with a search on our reference or the company's own.
     */
    public function index(Request $request, Property $property, PortalBookings $bookings): Response
    {
        $link = $this->link($request);
        $property->loadMissing('settings');
        $search = $request->string('search')->trim()->toString();

        return Inertia::render('portal/bookings/index', [
            'bookings' => PortalBookingRowData::collect($bookings->paginate($link->company, $search), PaginatedDataCollection::class),
            'propertyCode' => $property->code,
            'search' => $search,
            'companySuspended' => $link->company->status === CompanyStatus::Suspended,
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * One booking.
     */
    public function show(Request $request, Property $property, int $reservation, PortalBookings $bookings): Response
    {
        $link = $this->link($request);
        $property->loadMissing('settings');

        return Inertia::render('portal/bookings/show', [
            'propertyCode' => $property->code,
            'booking' => $bookings->detail($link->company, $bookings->find($link->company, $reservation), $property),
            'companySuspended' => $link->company->status === CompanyStatus::Suspended,
            'idTypes' => IdentityDocumentType::options(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * Edit the booking's guests.
     */
    public function updateGuests(UpdatePortalGuestsRequest $request, Property $property, int $reservation, PortalBookings $bookings, UpdatePortalReservationGuests $updateGuests, PortalBookingMessages $messages): RedirectResponse
    {
        $link = $this->link($request);
        $result = $updateGuests($request->user(), $link, $bookings->find($link->company, $reservation), $request->guests());

        return $this->back($property, $reservation, $result, __('Guests saved.'), $messages);
    }

    /**
     * Cancel before the cut-off.
     */
    public function cancel(Request $request, Property $property, int $reservation, PortalBookings $bookings, CancelPortalReservation $cancel, PortalBookingMessages $messages): RedirectResponse
    {
        $link = $this->link($request);
        $result = $cancel($request->user(), $link, $bookings->find($link->company, $reservation));

        return $this->back($property, $reservation, $result, __('Booking cancelled.'), $messages);
    }

    /**
     * Ask the property to cancel after the cut-off.
     */
    public function requestCancellation(Request $request, Property $property, int $reservation, PortalBookings $bookings, RequestPortalCancellation $requestCancellation, PortalBookingMessages $messages): RedirectResponse
    {
        $link = $this->link($request);
        $result = $requestCancellation($request->user(), $link, $bookings->find($link->company, $reservation));

        return $this->back($property, $reservation, $result, __('We asked the property to cancel this booking.'), $messages);
    }

    private function back(Property $property, int $reservation, PortalBookingResult $result, string $success, PortalBookingMessages $messages): RedirectResponse
    {
        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $messages->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => $success]);

        return to_route('portal.bookings.show', ['property' => $property, 'reservation' => $reservation]);
    }

    private function link(Request $request): CompanyUser
    {
        $link = $request->attributes->get('portalLink');

        abort_unless($link instanceof CompanyUser, 404);

        return $link;
    }
}
