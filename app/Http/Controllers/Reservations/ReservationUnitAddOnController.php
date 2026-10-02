<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\AddUnitAddOn;
use App\Actions\Reservations\RemoveUnitAddOn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AddUnitAddOnRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReservationUnitAddOnController extends Controller
{
    /**
     * Add an add-on to a unit. A refusal goes back to the Dialog as an error on the matching field.
     */
    public function store(AddUnitAddOnRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, AddUnitAddOn $addUnitAddOn, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $addUnitAddOn($request->user(), $unit, $request->toAddOnData());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Add-on added.')]);

        return back(fallback: $this->page($property, $reservation));
    }

    /**
     * Remove one add-on row. It has no form, so a refusal is an error toast.
     */
    public function destroy(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, UnitAddOn $addOn, RemoveUnitAddOn $removeUnitAddOn): RedirectResponse
    {
        Inertia::flash('toast', $removeUnitAddOn($request->user(), $addOn)
            ? ['type' => 'success', 'message' => __('Add-on removed.')]
            : ['type' => 'error', 'message' => __('This add-on cannot be removed. It is already gone, or its unit is closed.')]);

        return back(fallback: $this->page($property, $reservation));
    }

    private function page(Property $property, Reservation $reservation): string
    {
        return route('reservations.show', ['property' => $property, 'reservation' => $reservation]);
    }
}
