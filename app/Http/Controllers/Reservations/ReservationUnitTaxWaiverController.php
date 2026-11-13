<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\RemoveUnitTaxWaiver;
use App\Actions\Folio\WaiveGroupTax;
use App\Actions\Folio\WaiveUnitTax;
use App\Enums\TaxWaiverReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\WaiveGroupTaxRequest;
use App\Http\Requests\Reservations\WaiveUnitTaxRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Taxes paid elsewhere: stop charging a tax for one unit (the unit panel) or several (More), or charge it again. See
 * docs/06-catalogues/03-taxes.md#tax-waivers.
 */
class ReservationUnitTaxWaiverController extends Controller
{
    public function store(WaiveUnitTaxRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, WaiveUnitTax $waiveUnitTax, FolioRefusals $refusals): RedirectResponse
    {
        $result = $waiveUnitTax($request->user(), $unit, $request->taxIds(), $request->reason() ?? TaxWaiverReason::PaidElsewhere, $request->string('paid_to')->toString(), $request->string('reference')->toString());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation, 'unit' => $unit->id]);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result->reversed > 0
            ? trans_choice('Tax waived. :count posted tax line was reversed.|Tax waived. :count posted tax lines were reversed.', $result->reversed)
            : __('Tax waived.')]);

        return back(fallback: $page);
    }

    public function storeGroup(WaiveGroupTaxRequest $request, Property $property, Reservation $reservation, WaiveGroupTax $waiveGroupTax, FolioRefusals $refusals): RedirectResponse
    {
        $result = $waiveGroupTax($request->user(), $reservation, $request->unitIds(), $request->taxIds(), $request->reason() ?? TaxWaiverReason::PaidElsewhere, $request->string('paid_to')->toString(), $request->string('reference')->toString());
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation]);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->errors((string) $result->refusal));
        }

        $message = trans_choice('Tax waived for :count room.|Tax waived for :count rooms.', $result->waived);

        if ($result->skipped > 0) {
            $message .= ' '.trans_choice(':count already had it.|:count already had it.', $result->skipped);
        }

        if ($result->reversed > 0) {
            $message .= ' '.trans_choice(':count posted tax line was reversed.|:count posted tax lines were reversed.', $result->reversed);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back(fallback: $page);
    }

    public function destroy(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, int $taxId, RemoveUnitTaxWaiver $removeUnitTaxWaiver, FolioRefusals $refusals): RedirectResponse
    {
        $result = $removeUnitTaxWaiver($request->user(), $unit, $taxId);
        $page = route('reservations.show', ['property' => $property, 'reservation' => $reservation, 'unit' => $unit->id]);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Tax waiver removed. Nights not charged yet will include the tax.')]);

        return back(fallback: $page);
    }
}
