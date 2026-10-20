<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Folio\AddLineTax;
use App\Actions\Folio\ChangeOpeningBalance;
use App\Actions\Folio\EditLine;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\RecalculateLineTax;
use App\Actions\Folio\RemoveLineTax;
use App\Actions\Folio\SplitLine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\ChangeLineTaxRequest;
use App\Http\Requests\Folio\ChangeOpeningBalanceRequest;
use App\Http\Requests\Folio\EditLinePreviewRequest;
use App\Http\Requests\Folio\EditLineRequest;
use App\Http\Requests\Folio\LineManualActionRequest;
use App\Http\Requests\Folio\SplitLineRequest;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Folio\EditLinePreview;
use App\Services\Folio\FolioRefusals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The row menu of the manual folio page: edit or split a posted line, and remove, add or recalculate its tax. The folio tab
 * adds Change opening balance. Each one posts at
 * once with its own reason. A refusal goes back to the Dialog as an error. A folio that is not on the reservation, or a
 * folio, tax or line that is not on the route's folio, is a 404. See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
 */
class ReservationFolioLineController extends Controller
{
    /**
     * The figures of the Edit line Dialog for the typed values. It writes nothing.
     */
    public function previewEdit(EditLinePreviewRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, EditLinePreview $preview): JsonResponse
    {
        abort_unless($folio->reservation_id === $reservation->id && $line->folio_id === $folio->id, 404);

        return response()->json($preview->preview($line, $folio, $request->edit()));
    }

    /**
     * Edit a charge's description, amount, quantity and taxes. A live room night changes its price through Change price
     * instead, so its Dialog does not come here.
     */
    public function edit(EditLineRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, EditLine $editLine, FolioRefusals $refusals): RedirectResponse
    {
        abort_unless($folio->reservation_id === $reservation->id && $line->folio_id === $folio->id, 404);

        return $this->answer($editLine($request->user(), $line, $request->edit(), $request->string('reason')->toString(), $request->string('idempotency_key')->toString()), $refusals, __('Line edited.'), $property, $reservation, $folio);
    }

    public function split(SplitLineRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, SplitLine $splitLine, FolioRefusals $refusals): RedirectResponse
    {
        $parts = $request->parts();
        $folioIds = $reservation->folios()->pluck('id')->all();

        foreach ($parts as $part) {
            abort_unless(in_array($part->folioId, $folioIds, true), 404);
        }

        return $this->answer($splitLine($request->user(), $line, $parts, $request->string('reason')->toString(), $request->string('idempotency_key')->toString()), $refusals, __('Line split.'), $property, $reservation, $folio);
    }

    public function removeTax(ChangeLineTaxRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, RemoveLineTax $removeLineTax, FolioRefusals $refusals): RedirectResponse
    {
        return $this->answer($removeLineTax($request->user(), $line, $request->taxIds(), $request->string('reason')->toString(), $request->string('idempotency_key')->toString()), $refusals, __('Tax removed.'), $property, $reservation, $folio);
    }

    public function addTax(ChangeLineTaxRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, AddLineTax $addLineTax, FolioRefusals $refusals): RedirectResponse
    {
        return $this->answer($addLineTax($request->user(), $line, $request->taxIds(), $request->string('reason')->toString(), $request->string('idempotency_key')->toString()), $refusals, __('Tax added.'), $property, $reservation, $folio);
    }

    public function recalculateTax(LineManualActionRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, RecalculateLineTax $recalculateLineTax, FolioRefusals $refusals): RedirectResponse
    {
        return $this->answer($recalculateLineTax($request->user(), $line, $request->string('reason')->toString(), $request->string('idempotency_key')->toString()), $refusals, __('Tax recalculated.'), $property, $reservation, $folio);
    }

    /**
     * Change the opening balance of a folio from the line's row menu: the old line is reversed and the new one posted.
     */
    public function changeOpeningBalance(ChangeOpeningBalanceRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, ChangeOpeningBalance $changeOpeningBalance, FolioRefusals $refusals): RedirectResponse
    {
        abort_unless($folio->reservation_id === $reservation->id && $line->folio_id === $folio->id, 404);

        return $this->answer($changeOpeningBalance($request->user(), $line, $request->string('amount')->toString(), $request->string('reason')->toString()), $refusals, __('Opening balance changed.'), $property, $reservation, $folio);
    }

    private function answer(PostingResult $result, FolioRefusals $refusals, string $message, Property $property, Reservation $reservation, Folio $folio): RedirectResponse
    {
        $back = back(fallback: route('reservations.folios.manual', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio]));

        if ($result->isRefused()) {
            return $back->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return $back;
    }
}
