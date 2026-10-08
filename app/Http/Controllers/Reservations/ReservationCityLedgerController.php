<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Models\Folio;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\CityLedger\ArInvoiceRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReservationCityLedgerController extends Controller
{
    /**
     * Move a folio's balance to the city ledger and open its invoice. A refusal comes back as an error toast, except the credit limit, which comes back as an error on `credit_override_reason` so the Dialog can ask for a reason.
     */
    public function settle(Request $request, Property $property, Reservation $reservation, Folio $folio, SettleToCityLedger $settleToCityLedger, ArInvoiceRefusals $refusals): RedirectResponse
    {
        $result = $settleToCityLedger($request->user(), $folio, $request->user()->can(PermissionEnum::CompanyCreditOverride->value) ? $request->input('credit_override_reason') : null);

        if ($result->refusal === ArInvoiceResult::REFUSAL_CREDIT_LIMIT_EXCEEDED) {
            return back(fallback: route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]))
                ->withErrors($refusals->errors($result->refusal));
        }

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal, $result->rooms)]
            : ['type' => 'success', 'message' => __('Settled to City ledger. Invoice :number issued.', ['number' => $result->invoice?->number])]);

        return back(fallback: route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]));
    }
}
