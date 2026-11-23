<?php

namespace App\Http\Controllers\Facilities;

use App\Actions\Facilities\BookFacility;
use App\Actions\Facilities\CancelFacilityBooking;
use App\Actions\Facilities\UpdateFacilityBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityBookingRequest;
use App\Http\Requests\Facilities\QuoteFacilityBookingRequest;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Property;
use App\Services\Facilities\FacilityBookingQuote;
use App\Services\Facilities\FacilityBookingRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Book, change and cancel facility bookings, and quote one for the Book facility Sheet. Bookings belong to events
 * for now; reservations come with the next item. See docs/33-facilities/04-facility-bookings.md.
 */
class FacilityBookingController extends Controller
{
    public function store(FacilityBookingRequest $request, Property $property, BookFacility $bookFacility, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $event = Event::query()->where('property_id', $property->id)->findOrFail($request->integer('event_id'));
        $result = $bookFacility->forEvent($request->user(), $event, $request->toInputData());

        if ($result->isRefused()) {
            return back()->withErrors($refusals->errors($result));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':facility booked.', ['facility' => $result->booking?->facility->name])]);

        return back();
    }

    public function update(FacilityBookingRequest $request, Property $property, FacilityBooking $facilityBooking, UpdateFacilityBooking $updateBooking, FacilityBookingRefusals $refusals): RedirectResponse
    {
        abort_if($facilityBooking->event_id === null, 404);

        $result = $updateBooking($request->user(), $facilityBooking, $request->toInputData());

        if ($result->isRefused()) {
            return back()->withErrors($refusals->errors($result));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking saved.')]);

        return back();
    }

    public function cancel(Request $request, Property $property, FacilityBooking $facilityBooking, CancelFacilityBooking $cancelBooking): RedirectResponse
    {
        abort_if($facilityBooking->event_id === null, 404);

        Inertia::flash('toast', $cancelBooking($request->user(), $facilityBooking)
            ? ['type' => 'success', 'message' => __('Booking cancelled.')]
            : ['type' => 'error', 'message' => __('This booking is already cancelled.')]);

        return back();
    }

    public function quote(QuoteFacilityBookingRequest $request, Property $property, FacilityBookingQuote $quote): JsonResponse
    {
        $event = Event::query()->where('property_id', $property->id)->findOrFail($request->integer('event_id'));
        $facility = Facility::query()->where('property_id', $property->id)->findOrFail($request->integer('facility_id'));
        $booking = $request->filled('booking_id')
            ? FacilityBooking::query()->where('event_id', $event->id)->findOrFail($request->integer('booking_id'))
            : null;

        return response()->json($quote->forEvent($event, $facility, $request->toInputData(), $booking));
    }
}
