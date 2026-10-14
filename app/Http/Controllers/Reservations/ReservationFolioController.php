<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\Folio\ApplyDiscount;
use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\RemoveDiscount;
use App\Actions\Folio\ReopenFolio;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\VoidInvoiceAndReopen;
use App\Data\Folio\FolioDiscountQuoteResponseData;
use App\Enums\FolioStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Folio\FolioVoucherRequest;
use App\Http\Requests\Folio\QuoteFolioDiscountRequest;
use App\Http\Requests\Folio\StoreFolioChargeRequest;
use App\Http\Requests\Folio\StoreFolioDiscountRequest;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\CityLedger\ArInvoiceRefusals;
use App\Services\Folio\DiscountQuote;
use App\Services\Folio\FolioInvoice;
use App\Services\Folio\FolioRefusals;
use App\Services\Folio\FolioReopenRule;
use App\Services\Folio\FolioVoucher;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class ReservationFolioController extends Controller
{
    /**
     * Post a charge on the folio the form names (`folio_id`; the route folio is only the page to return to). A Unit
     * folio uses its own unit, and a Master or Third party folio posts for the unit the form names. Routing does not apply. A refusal
     * goes back to the Dialog as an error on the matching field.
     */
    public function storeCharge(StoreFolioChargeRequest $request, Property $property, Reservation $reservation, Folio $folio, PostCharge $postCharge, FolioRefusals $refusals): RedirectResponse
    {
        $target = Folio::query()->where('reservation_id', $reservation->id)->find($request->integer('folio_id'));
        $unit = $reservation->units()->findOrFail($target->reservation_unit_id ?? $request->integer('unit_id'));
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
     * Quote a discount for the folio: the eligible charges, the amount and the fingerprint that `store` sends back.
     * It writes nothing. A discount that is not the property's is a 404.
     */
    public function quoteDiscount(QuoteFolioDiscountRequest $request, Property $property, Reservation $reservation, Folio $folio, DiscountQuote $discountQuote, CurrentBusinessDate $currentBusinessDate, FolioRefusals $refusals): JsonResponse
    {
        $discount = $this->discount($property, $request->integer('discount_id'));
        $businessDate = $currentBusinessDate->date($property);

        if ($businessDate === null) {
            abort(422, $refusals->message(PostingResult::REFUSAL_NO_BUSINESS_DATE));
        }

        $quote = $discountQuote->quote($folio, $discount, $businessDate, $request->amount());
        $refusal = $quote->lines === [] ? PostingResult::REFUSAL_NOTHING_ELIGIBLE : $quote->amountRefusal;

        return response()->json(new FolioDiscountQuoteResponseData($quote, $refusal === null ? null : $refusals->message($refusal)));
    }

    /**
     * Apply a quoted discount. The fingerprint must still match, or the Action refuses with `quote_changed`. A refusal
     * goes back to the Dialog as an error.
     */
    public function storeDiscount(StoreFolioDiscountRequest $request, Property $property, Reservation $reservation, Folio $folio, ApplyDiscount $applyDiscount, FolioRefusals $refusals): RedirectResponse
    {
        $discount = $this->discount($property, $request->integer('discount_id'));
        $result = $applyDiscount($request->user(), $folio, $discount, $request->amount(), $request->string('fingerprint')->toString());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $reservation, $folio))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discount :name applied.', ['name' => $discount->name])]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    /**
     * Remove a discount from its charge. The charge stays. It has no form, so a refusal is an error toast.
     */
    public function destroyDiscount(Request $request, Property $property, Reservation $reservation, Folio $folio, FolioLine $line, RemoveDiscount $removeDiscount, FolioRefusals $refusals): RedirectResponse
    {
        $result = $removeDiscount($request->user(), $line);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Discount removed.')]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    /**
     * Close an Open folio with a balance of 0. It has no form, so a refusal is an error toast.
     */
    public function close(Request $request, Property $property, Reservation $reservation, Folio $folio, CloseFolio $closeFolio, FolioRefusals $refusals): RedirectResponse
    {
        $result = $closeFolio($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal, $result->rooms)]
            : ['type' => 'success', 'message' => __('Folio :number closed.', ['number' => $folio->number])]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    /**
     * Reopen a Closed folio. `FolioReopenRule` decides who may (`folio.reopen`, or `front-desk.check-out` for a Unit
     * folio closed on the current business date); anyone else gets a 403. A refusal of the Action is an error toast.
     */
    public function reopen(Request $request, Property $property, Reservation $reservation, Folio $folio, ReopenFolio $reopenFolio, FolioRefusals $refusals, FolioReopenRule $reopenRule): RedirectResponse
    {
        abort_unless($reopenRule->allows($request->user(), $folio), 403);

        $result = $reopenFolio($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Folio :number reopened.', ['number' => $folio->number])]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    /**
     * Void the folio's AR invoice and reopen the folio in one step (`city-ledger.invoice` and `folio.reopen`, checked by
     * the route). A refusal rolls both back and is an error toast.
     */
    public function voidInvoiceAndReopen(Request $request, Property $property, Reservation $reservation, Folio $folio, VoidInvoiceAndReopen $voidInvoiceAndReopen, FolioRefusals $refusals, ArInvoiceRefusals $invoiceRefusals, FolioReopenRule $reopenRule): RedirectResponse
    {
        $invoiceNumber = $reopenRule->billingInvoice($folio)?->number;
        $result = $voidInvoiceAndReopen($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => in_array($result->refusal, [ArInvoiceResult::REFUSAL_NOT_VOIDABLE, ArInvoiceResult::REFUSAL_ALREADY_VOID], true) ? $invoiceRefusals->message((string) $result->refusal) : $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Invoice :number voided. The folio is open again.', ['number' => $invoiceNumber])]);

        return back(fallback: $this->page($property, $reservation, $folio));
    }

    private function discount(Property $property, int $id): Discount
    {
        return Discount::query()->where('property_id', $property->id)->active()->findOrFail($id);
    }

    /**
     * A voucher for the posted lines in `?lines[]=` and the Scheduled rows in `?scheduled[]=` (row keys), as an A4 PDF
     * shown inline so staff can print or save it. It is rendered on demand and not stored. A line or Scheduled row that
     * is not on this folio is a 404. A line that cannot be printed (a payment, a reversal or a reversed line) is a 422,
     * because the dialog never offers one.
     */
    public function voucher(FolioVoucherRequest $request, Property $property, Reservation $reservation, Folio $folio, FolioVoucher $voucher, PrintedPdf $printedPdf): Response
    {
        $ids = $request->lineIds();
        $keys = $request->scheduledKeys();

        abort_unless($folio->lines()->whereIn('id', $ids)->count() === count($ids), 404);
        abort_unless($voucher->printableLines($folio)->whereIn('id', $ids)->count() === count($ids), 422);

        $scheduled = $keys === [] ? collect() : $voucher->scheduledRows($property, $reservation, $folio)->whereIn('key', $keys)->values();

        abort_unless($scheduled->count() === count($keys), 404);

        return $printedPdf->make($property, 'pdf.voucher', ['voucher' => $voucher->forLines($folio, $ids, $scheduled)])
            ->stream('voucher-'.$folio->number.'.pdf');
    }

    /**
     * The invoice of a Closed folio as an A4 PDF shown inline. It is rendered on demand and stores nothing. An Open or
     * Voided folio, or a folio of another reservation, is a 404.
     */
    public function invoice(Property $property, Reservation $reservation, Folio $folio, FolioInvoice $folioInvoice, PrintedPdf $printedPdf): Response
    {
        abort_unless($folio->reservation_id === $reservation->id && $folio->status === FolioStatus::Closed && $folio->invoice_number !== null, 404);

        return $printedPdf->make($property, 'pdf.folio-invoice', [
            'invoice' => $folioInvoice->for($folio),
            'settings' => $property->printSetting,
        ])->stream('invoice-'.$folio->invoice_number.'.pdf');
    }

    /**
     * A voucher of Scheduled rows for a unit that has no folio yet (nothing has posted for it). Only `?scheduled[]=`
     * applies. A row that does not belong to this unit's "not started" entry is a 404.
     */
    public function unitVoucher(FolioVoucherRequest $request, Property $property, Reservation $reservation, ReservationUnit $unit, FolioVoucher $voucher, PrintedPdf $printedPdf): Response
    {
        $keys = $request->scheduledKeys();

        abort_unless($request->lineIds() === [], 422);

        $scheduled = $voucher->scheduledRows($property, $reservation, null, $unit)->whereIn('key', $keys)->values();

        abort_unless($scheduled->count() === count($keys), 404);

        return $printedPdf->make($property, 'pdf.voucher', ['voucher' => $voucher->forUnit($property, $unit, $scheduled)])
            ->stream('voucher-'.$reservation->reference.'.pdf');
    }

    private function page(Property $property, Reservation $reservation, Folio $folio): string
    {
        return route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]);
    }
}
