<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\ChangeUnitParty;
use App\Data\Reservations\UnitPartyNightData;
use App\Data\Reservations\UnitPartyPreviewData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ChangeUnitPartyRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRefusals;
use App\Services\Reservations\UnitPartyChangePreview;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Change guests on the room panel: the adults and child ages of one unit, and the new prices. See
 * docs/12-reservations/14-change-guests.md.
 */
class ReservationUnitPartyController extends Controller
{
    public function preview(ChangeUnitPartyRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, UnitPartyChangePreview $preview, ReservationRefusals $refusals): JsonResponse
    {
        $plan = $preview->plan($unit, $request->adults(), $request->childAges(), $request->repriceCharged());

        if ($plan->isRefused()) {
            return response()->json(new UnitPartyPreviewData([], '0.00', '0.00', '0.00', false, false, $refusals->partyMessage((string) $plan->refusal)));
        }

        $nights = array_map(fn (array $row): UnitPartyNightData => new UnitPartyNightData(
            $row['night']->date->toDateString(),
            (string) BigDecimal::of($row['night']->amount)->toScale(2),
            $row['changes'] && $row['price'] !== null ? (string) $row['price']->net()->getAmount() : (string) BigDecimal::of($row['night']->amount)->toScale(2),
            $row['charged'],
            $row['overridden'],
            $row['changes'],
        ), $plan->nights);

        return response()->json(new UnitPartyPreviewData(
            $nights,
            $plan->before,
            $plan->after,
            (string) BigDecimal::of($plan->after)->minus($plan->before)->toScale(2),
            $plan->fromChannel,
            array_any($plan->nights, fn (array $row): bool => $row['charged']),
            null,
        ));
    }

    public function store(ChangeUnitPartyRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, ChangeUnitParty $changeUnitParty, ReservationRefusals $refusals): RedirectResponse
    {
        $result = $changeUnitParty($request->user(), $unit, $request->adults(), $request->childAges(), $request->repriceCharged());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation, 'unit' => $unit->id]);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors([ReservationRefusals::FORM => $refusals->partyMessage((string) $result->refusal)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result->corrected > 0
            ? trans_choice('Guests changed. :count charged night was charged again.|Guests changed. :count charged nights were charged again.', $result->corrected)
            : __('Guests changed.')]);

        return back(fallback: $page);
    }
}
