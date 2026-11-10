<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\AddGroupAddOn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AddGroupAddOnRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\GroupAddOnPreview;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The group add-on: the same add-on on several units of a reservation. See docs/12-reservations/04-add-ons.md#group-add-on.
 */
class ReservationAddOnController extends Controller
{
    /**
     * What each picked unit would get, read only, for the Dialog's preview panel.
     */
    public function preview(AddGroupAddOnRequest $request, Property $property, Reservation $reservation, GroupAddOnPreview $preview): JsonResponse
    {
        return response()->json($preview->preview($reservation, $request->unitIds(), $request->toAddOnData()));
    }

    /**
     * Add the add-on to every picked unit, all or nothing. A refusal goes back to the Dialog and names the unit.
     */
    public function store(AddGroupAddOnRequest $request, Property $property, Reservation $reservation, AddGroupAddOn $addGroupAddOn, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $addGroupAddOn($request->user(), $reservation, $request->unitIds(), $request->toAddOnData());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->groupAddOnErrors((string) $result->refusal, $result->failedUnit));
        }

        $name = $result->addOns[0]->chargeItem->name ?? __('Add-on');
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':item added to :count unit.|:item added to :count units.', $result->units, ['item' => $name])]);

        return back(fallback: $page);
    }
}
