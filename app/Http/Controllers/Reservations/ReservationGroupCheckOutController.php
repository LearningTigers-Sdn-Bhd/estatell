<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\FrontDesk\CheckOutGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\CheckOutGroupPreviewRequest;
use App\Http\Requests\Reservations\CheckOutGroupRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\FrontDesk\GroupCheckOutPreview;
use App\Services\FrontDesk\GroupCheckOutRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Group check-out: the read-only preview of the rooms and choices, and the save through `CheckOutGroup`. A unit of
 * another reservation is a 404. See docs/18-front-desk/05-group-check-out.md.
 */
class ReservationGroupCheckOutController extends Controller
{
    /**
     * The rooms, the Master line and the summary. It writes nothing.
     */
    public function preview(CheckOutGroupPreviewRequest $request, Property $property, Reservation $reservation, GroupCheckOutPreview $preview): JsonResponse
    {
        $this->units($request, $reservation);

        return response()->json($preview->forReservation(
            $reservation,
            $request->user(),
            $request->hasRows() ? $request->groupRows() : null,
            $request->onePayment(),
            $request->settleMaster(),
        ));
    }

    /**
     * Check out the chosen rooms and settle their money in one save. A refusal saves nothing and goes back as an error
     * on its room (`units.{id}`) or on the form.
     */
    public function store(CheckOutGroupRequest $request, Property $property, Reservation $reservation, CheckOutGroup $checkOutGroup, GroupCheckOutRefusals $refusals): RedirectResponse
    {
        $this->units($request, $reservation);
        $result = $checkOutGroup($request->user(), $reservation, $request->groupRows(), $request->onePayment(), $request->settleMaster());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Nothing was saved. Check the rooms marked with an error.')]);

            return back(fallback: $page)->withErrors($refusals->errors($result));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count room checked out.|:count rooms checked out.', count($result->checkedOutUnitIds))]);

        return back(fallback: $page);
    }

    private function units(CheckOutGroupRequest $request, Reservation $reservation): void
    {
        $ids = array_values(array_unique($request->unitIds()));

        abort_unless($reservation->units()->whereIn('id', $ids)->count() === count($ids), 404);
    }
}
