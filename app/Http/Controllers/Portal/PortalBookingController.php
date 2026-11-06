<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Boats\SetBoatTrips;
use App\Actions\Portal\CancelPortalReservation;
use App\Actions\Portal\PortalBookingResult;
use App\Actions\Portal\RequestPortalCancellation;
use App\Actions\Portal\UpdatePortalReservationGuests;
use App\Data\Payments\PaymentProofData;
use App\Data\Portal\PortalBookingRowData;
use App\Data\Property\CurrencyData;
use App\Data\Reservations\PaymentScheduleData;
use App\Enums\CompanyStatus;
use App\Enums\IdentityDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UpdatePortalBoatsRequest;
use App\Http\Requests\Portal\UpdatePortalGuestsRequest;
use App\Models\CompanyUser;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use App\Services\Portal\PortalBookingMessages;
use App\Services\Portal\PortalBookings;
use App\Services\Reservations\AgentBill;
use App\Services\Reservations\ReservationRefusals;
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
    public function show(Request $request, Property $property, int $reservation, PortalBookings $bookings, AgentBill $agentBill): Response
    {
        $link = $this->link($request);
        $property->loadMissing('settings');

        $booking = $bookings->find($link->company, $reservation);

        return Inertia::render('portal/bookings/show', [
            'propertyCode' => $property->code,
            'booking' => $bookings->detail($link->company, $booking, $property),
            'paymentSchedule' => PaymentScheduleData::forReservation($booking),
            'agentBill' => $agentBill->for($booking),
            'paymentProofs' => array_values(PaymentProof::query()->where('reservation_id', $booking->id)->with('uploader')->latest('id')->get()
                ->map(fn (PaymentProof $proof): PaymentProofData => PaymentProofData::fromModel(
                    $proof,
                    route('portal.bookings.payment-proofs.file', ['property' => $property->code, 'reservation' => $booking->id, 'paymentProof' => $proof->id], absolute: false),
                ))->all()),
            'companySuspended' => $link->company->status === CompanyStatus::Suspended,
            'idTypes' => IdentityDocumentType::options(),
            'countries' => app(CountryOptions::class)->options(),
            'boatDepartures' => $bookings->boatDepartures($property),
            'boatSetup' => $bookings->boatSetup($link->company, $booking, $property),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * Set the boats of the booking's rooms: Boat in until check-in, Boat out until check-out. One refusal saves nothing.
     */
    public function updateBoats(UpdatePortalBoatsRequest $request, Property $property, int $reservation, PortalBookings $bookings, SetBoatTrips $setBoatTrips, ReservationRefusals $refusals): RedirectResponse
    {
        $link = $this->link($request);
        $booking = $bookings->find($link->company, $reservation);
        $page = route('portal.bookings.show', ['property' => $property, 'reservation' => $reservation]);

        if ($link->company->status === CompanyStatus::Suspended) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Your company is suspended. Contact the property.')]);

            return back(fallback: $page);
        }

        $result = $setBoatTrips($request->user(), $booking, $request->changes());

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->boatTripsErrors((string) $result->refusal, (string) $result->unitLabel));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boats saved.')]);

        return back(fallback: $page);
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
