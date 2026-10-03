<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\StoreFolioChargeRequest;
use App\Http\Requests\Folio\StoreFolioTransferRequest;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Folio\FolioRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReservationFolioController extends Controller
{
    /**
     * Post a charge for a unit. A Unit folio uses its own unit, and a Master or Third party folio posts for the unit
     * the form names. `FolioRouter` picks the folio, so the toast names the folio that received the charge. A refusal
     * goes back to the Dialog as an error on the matching field.
     */
    public function storeCharge(StoreFolioChargeRequest $request, Property $property, Reservation $reservation, Folio $folio, PostCharge $postCharge, FolioRefusals $refusals): RedirectResponse
    {
        $unit = $reservation->units()->findOrFail($folio->reservation_unit_id ?? $request->integer('unit_id'));
        $result = $postCharge($request->user(), $unit, $request->toChargeData());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation, $folio))->withErrors($refusals->errors((string) $result->refusal));
        }

        $landed = Folio::query()->findOrFail($result->line()->folio_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charge posted to folio :number.', ['number' => $landed->number])]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    /**
     * Reverse one charge with its tax and discount lines. It has no form, so a refusal is an error toast.
     */
    public function reverse(Request $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, ReverseLine $reverseLine, FolioRefusals $refusals): RedirectResponse
    {
        $result = $reverseLine($request->user(), $line);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Line reversed.')]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    /**
     * Move charges, with their children, to another folio of the same reservation. A line or folio outside the
     * reservation is a 404. A refusal goes back to the Dialog as an error.
     */
    public function transfer(StoreFolioTransferRequest $request, Property $property, Reservation $reservation, Folio $folio, TransferLines $transferLines, FolioRefusals $refusals): RedirectResponse
    {
        $lines = $folio->lines()->findOrFail(array_values(array_unique($request->array('line_ids'))))->all();
        $target = $reservation->folios()->findOrFail($request->integer('target_folio_id'));
        $result = $transferLines($request->user(), $lines, $target);

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation, $folio))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Moved to folio :number.', ['number' => $target->number])]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    private function page(Property $property, Reservation $reservation, Folio $folio): string
    {
        return route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]);
    }
}
