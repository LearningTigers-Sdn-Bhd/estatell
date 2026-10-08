<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\CityLedger\SettleToCityLedger;
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
     * Move a folio's balance to the city ledger and open its invoice. The credit limit never refuses it. A refusal comes back as an error toast.
     */
    public function settle(Request $request, Property $property, Reservation $reservation, Folio $folio, SettleToCityLedger $settleToCityLedger, ArInvoiceRefusals $refusals): RedirectResponse
    {
        $result = $settleToCityLedger($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal, $result->rooms)]
            : ['type' => 'success', 'message' => __('Settled to City ledger. Invoice :number issued.', ['number' => $result->invoice?->number])]);

        return back(fallback: route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]));
    }
}
