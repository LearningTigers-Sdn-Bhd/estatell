<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Guests\UpdateGuest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\UpdateUnitGuestRequest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationBookerController extends Controller
{
    /**
     * Save the profile of the booker, for a booker who does not stay and so has no unit to save through.
     */
    public function update(UpdateUnitGuestRequest $request, Property $property, Reservation $reservation, UpdateGuest $updateGuest): RedirectResponse
    {
        $booker = $reservation->booker;

        $saved = $updateGuest($request->user(), $booker, $request->profile(), array_values($booker->tags()->pluck('name')->all()));

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->input('full_name')])]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $booker->full_name])]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
