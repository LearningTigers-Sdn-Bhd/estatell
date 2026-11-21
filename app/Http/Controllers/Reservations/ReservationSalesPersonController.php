<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\UpdateReservationSalesPerson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\UpdateReservationSalesPersonRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationSalesPersonController extends Controller
{
    /**
     * Set or clear the sales person. A refusal goes back to the Dialog as an error on the sales person field.
     */
    public function update(UpdateReservationSalesPersonRequest $request, Property $property, Reservation $reservation, UpdateReservationSalesPerson $updateSalesPerson, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $updateSalesPerson($request->user(), $reservation, $request->filled('sales_person_id') ? $request->integer('sales_person_id') : null);

        if ($result->isRefused()) {
            return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]))
                ->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sales person saved.')]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
