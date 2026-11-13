<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\MoveFolioCredit;
use App\Actions\Folio\UndoCreditMove;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\MoveFolioCreditRequest;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Folio\FolioRefusals;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Move credit from one folio of a reservation to the others that owe, and undo a move. See
 * docs/13-folio/02-posting-charges.md#move-credit.
 */
class ReservationCreditMoveController extends Controller
{
    public function store(MoveFolioCreditRequest $request, Property $property, Reservation $reservation, Folio $folio, MoveFolioCredit $moveFolioCredit, FolioRefusals $refusals): RedirectResponse
    {
        $result = $moveFolioCredit($request->user(), $folio, $request->moves(), $request->note(), $request->string('idempotency_key')->toString());
        $page = $this->page($property, $reservation, $folio);

        if ($result->isRefused()) {
            return back(fallback: $page)->withErrors($refusals->errors((string) $result->refusal));
        }

        $moved = collect($result->lines)
            ->filter(fn (FolioLine $line): bool => $line->folio_id === $folio->id)
            ->reduce(fn (BigDecimal $sum, FolioLine $line): BigDecimal => $sum->plus($line->amount), BigDecimal::zero());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credit of :amount moved.', ['amount' => (string) $moved->toScale(2)])]);

        return back(fallback: $page);
    }

    public function undo(Request $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, UndoCreditMove $undoCreditMove, FolioRefusals $refusals): RedirectResponse
    {
        $result = $undoCreditMove($request->user(), $line);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Credit move undone.')]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    private function page(Property $property, Reservation $reservation, Folio $folio): string
    {
        return route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]);
    }
}
