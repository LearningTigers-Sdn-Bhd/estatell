<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\SaveReservationRemarks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\SaveReservationRemarksRequest;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationRemarksController extends Controller
{
    /**
     * Save the remarks of the booking from the Edit remarks Dialog.
     */
    public function update(SaveReservationRemarksRequest $request, Property $property, Reservation $reservation, SaveReservationRemarks $saveRemarks): RedirectResponse
    {
        $saveRemarks($request->user(), $reservation, $request->remarks());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Remarks saved.')]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
