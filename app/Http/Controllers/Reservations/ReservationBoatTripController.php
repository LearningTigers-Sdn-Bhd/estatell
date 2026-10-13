<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Boats\ClearBoatTrip;
use App\Actions\Boats\SetBoatTrip;
use App\Actions\Boats\SetBoatTrips;
use App\Enums\BoatDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\BoatTripRequest;
use App\Http\Requests\Reservations\BoatTripsRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Set or clear one direction of a unit's boat trip from the Units tab. See docs/24-boats/02-boat-trips.md#setting-a-trip.
 */
class ReservationBoatTripController extends Controller
{
    /**
     * A refusal goes back to the Dialog as an error on the field it belongs to. Nothing is saved.
     */
    public function set(BoatTripRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, BoatDirection $direction, SetBoatTrip $setBoatTrip, ReservationRefusals $refusals): RedirectResponse
    {
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation, 'unit' => $unit->id]);
        $result = $setBoatTrip($request->user(), $unit, $request->toTripData($direction));

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->boatTripErrors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat trip saved.')]);

        return back(fallback: $page);
    }

    /**
     * Set or clear the trips of several units at once, from the Set boats Dialog. One refusal saves nothing and the error
     * names the unit. Only the units the Dialog sent are changed.
     */
    public function setMany(BoatTripsRequest $request, Property $property, Reservation $reservation, SetBoatTrips $setBoatTrips, ReservationRefusals $refusals): RedirectResponse
    {
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);
        $result = $setBoatTrips($request->user(), $reservation, $request->changes());

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->boatTripsErrors((string) $result->refusal, (string) $result->unitLabel));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat trips saved.')]);

        return back(fallback: $page);
    }

    /**
     * Clear a direction to "Not set". A refusal is an error toast and nothing is written.
     */
    public function clear(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, BoatDirection $direction, ClearBoatTrip $clearBoatTrip, ReservationRefusals $refusals): RedirectResponse
    {
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation, 'unit' => $unit->id]);
        $result = $clearBoatTrip($request->user(), $unit, $direction);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) current($refusals->boatTripErrors((string) $result->refusal))]);

            return back(fallback: $page);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Boat trip cleared.')]);

        return back(fallback: $page);
    }
}
