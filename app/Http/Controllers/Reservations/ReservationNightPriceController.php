<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\ChangeGroupNightPrice;
use App\Actions\Reservations\ChangeNightPrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ChangeGroupNightPriceRequest;
use App\Http\Requests\Reservations\ChangeNightPriceRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Reservations\GroupNightPricePreview;
use App\Services\Reservations\NightPriceChangePreview;
use App\Services\Reservations\ReservationRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Change price on the Units tab night table, Correct price… on a posted room line, and the group Change price in
 * the Units tab bulk bar. See docs/12-reservations/09-change-night-price.md.
 */
class ReservationNightPriceController extends Controller
{
    public function preview(ChangeNightPriceRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, NightPriceChangePreview $preview): JsonResponse
    {
        return response()->json($preview->preview($unit, $request->nightIds(), $request->price()));
    }

    /**
     * Set the price of the ticked nights. A refusal goes back to the Dialog as an error on the field it belongs to.
     */
    public function store(ChangeNightPriceRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, ChangeNightPrice $changeNightPrice, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $changeNightPrice($request->user(), $unit, $request->nights(), (string) $request->price(), $request->string('reason')->toString());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation, 'unit' => $unit->id]);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->nightPriceErrors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $result->changed === 0 => __('The price is the same as now.'),
            $result->corrected > 0 => trans_choice('Price changed. :count charged night was charged again.|Price changed. :count charged nights were charged again.', $result->corrected),
            default => __('Price changed.'),
        }]);

        return back(fallback: $page);
    }

    /**
     * What the group Change price does to each ticked unit, read only, for its Dialog.
     */
    public function groupPreview(ChangeGroupNightPriceRequest $request, Property $property, Reservation $reservation, GroupNightPricePreview $preview): JsonResponse
    {
        return response()->json($preview->preview($reservation, $request->unitIds(), $request->price()));
    }

    /**
     * Set the price of every night of the ticked units, all or nothing. A refusal names the unit.
     */
    public function groupStore(ChangeGroupNightPriceRequest $request, Property $property, Reservation $reservation, ChangeGroupNightPrice $changeGroupNightPrice, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $changeGroupNightPrice($request->user(), $reservation, $request->unitIds(), (string) $request->price(), $request->string('reason')->toString());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->groupNightPriceErrors((string) $result->refusal, $result->failedUnit));
        }

        $changed = trans_choice('Price changed on :count unit.|Price changed on :count units.', $result->units);
        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $result->changed === 0 => __('The price is the same as now.'),
            $result->corrected > 0 => $changed.' '.trans_choice(':count charged night was charged again.|:count charged nights were charged again.', $result->corrected),
            default => $changed,
        }]);

        return back(fallback: $page);
    }
}
