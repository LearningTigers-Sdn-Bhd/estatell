<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\UpdateReservationMarketSegment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\UpdateReservationMarketSegmentRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReservationMarketSegmentController extends Controller
{
    /**
     * Set or clear the market segment. A refusal goes back to the Dialog as an error on the market segment field.
     */
    public function update(UpdateReservationMarketSegmentRequest $request, Property $property, Reservation $reservation, UpdateReservationMarketSegment $updateMarketSegment, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $updateMarketSegment($request->user(), $reservation, $request->filled('market_segment_id') ? $request->integer('market_segment_id') : null);

        if ($result->isRefused()) {
            return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]))
                ->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Market segment saved.')]);

        return back(fallback: route('reservations.show', ['property' => $property, 'reservation' => $reservation]));
    }
}
