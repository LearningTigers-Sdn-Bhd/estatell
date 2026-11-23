<?php

namespace App\Http\Controllers\Facilities;

use App\Data\Facilities\FacilityOptionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityOwnerEventsRequest;
use App\Models\Facility;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Facilities\FacilityOwnerSearch;
use App\Services\Facilities\ReservationFacilityBookings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The For step of Book facility on the Timeline View: find the reservation or event a new booking belongs to, then
 * what the Book facility Sheet needs for it. The routes need `facilities.book`.
 * See docs/33-facilities/05-timeline-and-report.md#book-facility-from-the-timeline.
 */
class FacilityOwnerController extends Controller
{
    /**
     * Live reservations matching `q`. The route also needs `reservations.view`.
     */
    public function reservations(Request $request, Property $property, FacilityOwnerSearch $search): JsonResponse
    {
        return response()->json($search->reservations($property, $request->string('q')->toString()));
    }

    /**
     * The facilities, Charge to options and default date for a reservation. The route also needs `reservations.view`.
     */
    public function reservation(Property $property, Reservation $reservation, FacilityOwnerSearch $search): JsonResponse
    {
        return response()->json($search->reservation($property, $reservation));
    }

    /**
     * The events on the date that take bookings, and the facilities. The route also needs `events.view`.
     */
    public function events(FacilityOwnerEventsRequest $request, Property $property, FacilityOwnerSearch $search, ReservationFacilityBookings $bookings): JsonResponse
    {
        return response()->json([
            'events' => $search->events($property, $request->bookingDate()),
            'facilities' => array_values($bookings->facilities($property)->map(fn (Facility $facility): FacilityOptionData => FacilityOptionData::fromModel($facility))->all()),
        ]);
    }
}
