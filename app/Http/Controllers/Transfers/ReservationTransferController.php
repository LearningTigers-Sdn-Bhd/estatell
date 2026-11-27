<?php

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\ClearUnitTransfer;
use App\Actions\Transfers\SetUnitTransfer;
use App\Enums\TransferDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\UnitTransferRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Transfers\TransferRefusals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Set or clear one direction of a unit's land transfer from the reservation Transfers tab.
 * See docs/35-transfers/01-land-transfers.md#reservation-transfers-tab.
 */
class ReservationTransferController extends Controller
{
    /**
     * A refusal goes back to the Dialog as an error on the field it belongs to. Nothing is saved.
     */
    public function set(UnitTransferRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, TransferDirection $direction, SetUnitTransfer $setTransfer, TransferRefusals $refusals): RedirectResponse
    {
        $page = route('reservations.transfers', ['property' => $property, 'reservation' => $reservation]);
        $result = $setTransfer($request->user(), $unit, $request->toInputData($direction));

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', $this->savedToast($result->postedChargeKept, __('Transfer saved.')));

        return back(fallback: $page);
    }

    /**
     * Remove a direction. A refusal is an error toast and nothing is written.
     */
    public function clear(Request $request, Property $property, Reservation $reservation, ReservationUnit $unit, TransferDirection $direction, ClearUnitTransfer $clearTransfer, TransferRefusals $refusals): RedirectResponse
    {
        $page = route('reservations.transfers', ['property' => $property, 'reservation' => $reservation]);
        $result = $clearTransfer($request->user(), $unit, $direction);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) current($refusals->errors((string) $result->refusal))]);

            return back(fallback: $page);
        }

        Inertia::flash('toast', $this->savedToast($result->postedChargeKept, __('Transfer removed.')));

        return back(fallback: $page);
    }

    /**
     * @return array{type: string, message: string}
     */
    private function savedToast(bool $postedChargeKept, string $message): array
    {
        if (! $postedChargeKept) {
            return ['type' => 'success', 'message' => $message];
        }

        return ['type' => 'warning', 'message' => $message.' '.__('The old transfer charge is already posted. Reverse it on the folio if needed.')];
    }
}
