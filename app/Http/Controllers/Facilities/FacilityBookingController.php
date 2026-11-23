<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\CancelFacilityBooking;
use App\Actions\Facilities\PostFacilityBooking;
use App\Actions\Facilities\UpdateFacilityBooking;
use App\Actions\Folio\CreateFolio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityBookingRequest;
use App\Http\Requests\Facilities\QuoteFacilityBookingRequest;
use App\Http\Requests\Folio\StoreThirdPartyFolioRequest;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Facilities\FacilityBookingQuote;
use App\Services\Facilities\FacilityBookingRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Book, change and cancel facility bookings of events and reservations, quote one for the Book facility Sheet, and
 * create the third-party folio of the Sheet's **+ New third-party folio…** step. See
 * docs/33-facilities/04-facility-bookings.md.
 */
class FacilityBookingController extends Controller
{
    public function store(FacilityBookingRequest $request, Property $property, BookFacility $bookFacility, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $result = $request->filled('reservation_id')
            ? $bookFacility->forReservation($request->user(), Reservation::query()->where('property_id', $property->id)->findOrFail($request->integer('reservation_id')), $request->toInputData())
            : $bookFacility->forEvent($request->user(), Event::query()->where('property_id', $property->id)->findOrFail($request->integer('event_id')), $request->toInputData());

        if ($result->isRefused()) {
            return back()->withErrors($refusals->errors($result));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':facility booked.', ['facility' => $result->booking?->facility->name])]);

        return back();
    }

    public function update(FacilityBookingRequest $request, Property $property, FacilityBooking $facilityBooking, UpdateFacilityBooking $updateBooking, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $result = $updateBooking($request->user(), $facilityBooking, $request->toInputData());

        if ($result->isRefused()) {
            return back()->withErrors($refusals->errors($result));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking saved.')]);

        return back();
    }

    public function cancel(Request $request, Property $property, FacilityBooking $facilityBooking, CancelFacilityBooking $cancelBooking, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $result = $cancelBooking($request->user(), $facilityBooking);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message($result)]
            : ['type' => 'success', 'message' => __('Booking cancelled.')]);

        return back();
    }

    /**
     * Post now: post a Confirmed booking to its folio before its date. The route also needs `folio.post-charge`.
     */
    public function post(Request $request, Property $property, FacilityBooking $facilityBooking, PostFacilityBooking $postBooking, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $result = $postBooking($request->user(), $facilityBooking);

        Inertia::flash('toast', match (true) {
            $result->isRefused() => ['type' => 'error', 'message' => $refusals->postingMessage((string) $result->refusal)],
            $result->replayed => ['type' => 'error', 'message' => __('This booking is already on the folio.')],
            default => ['type' => 'success', 'message' => __('Charge posted to the folio.')],
        });

        return back();
    }

    public function quote(QuoteFacilityBookingRequest $request, Property $property, FacilityBookingQuote $quote): JsonResponse
    {
        $facility = Facility::query()->where('property_id', $property->id)->findOrFail($request->integer('facility_id'));

        if ($request->filled('reservation_id')) {
            $reservation = Reservation::query()->where('property_id', $property->id)->findOrFail($request->integer('reservation_id'));
            $booking = $request->filled('booking_id')
                ? FacilityBooking::query()->where('reservation_id', $reservation->id)->findOrFail($request->integer('booking_id'))
                : null;

            return response()->json($quote->forReservation($reservation, $facility, $request->toInputData(), $booking));
        }

        $event = Event::query()->where('property_id', $property->id)->findOrFail($request->integer('event_id'));
        $booking = $request->filled('booking_id')
            ? FacilityBooking::query()->where('event_id', $event->id)->findOrFail($request->integer('booking_id'))
            : null;

        return response()->json($quote->forEvent($event, $facility, $request->toInputData(), $booking));
    }

    /**
     * The Book facility Sheet's payer step: create a third-party folio on the reservation and go back to the Sheet,
     * which picks it in Charge to (`chargeToFolioCreated` flash, `f{folio id}`). The route also needs `folio.transfer`,
     * as on the folio tab.
     */
    public function storeFolio(StoreThirdPartyFolioRequest $request, Property $property, Reservation $reservation, CreateFolio $createFolio): RedirectResponse
    {
        $guest = $request->payerGuest($property);
        $company = $guest === null ? $request->payerCompany($property) : null;

        $folio = $createFolio->thirdParty($reservation, $guest, $company?->id, $request->user());

        Inertia::flash('chargeToFolioCreated', 'f'.$folio->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Folio :number created for :payer.', ['number' => $folio->number, 'payer' => $guest->full_name ?? $company?->name])]);

        return back();
    }
}
