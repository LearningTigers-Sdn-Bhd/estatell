<?php

namespace App\Http\Controllers\Events;

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\SettleToCityLedger;
use App\Actions\Events\PostEventCharge;
use App\Actions\Folio\ApplyDiscount;
use App\Actions\Folio\CloseFolio;
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
use App\Models\Event;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
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

/**
 * The folio actions of the event page. They call the same Actions as the reservation folio tab; the route binds the
 * folio inside its event. See docs/33-facilities/03-events-and-event-folios.md.
 */
class EventFolioController extends Controller
{
    /**
     * Post a charge on the event folio. A refusal goes back to the Dialog as an error on the matching field.
     */
    public function storeCharge(StoreFolioChargeRequest $request, Property $property, Event $event, Folio $folio, PostEventCharge $postEventCharge, FolioRefusals $refusals): RedirectResponse
    {
        $result = $postEventCharge($request->user(), $folio, $request->toChargeData());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $event))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charge posted to folio :number.', ['number' => $folio->number])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Reverse one charge with its tax and discount lines. A refusal is an error toast.
     */
    public function reverse(Request $request, Property $property, Event $event, Folio $folio, FolioLine $line, ReverseLine $reverseLine, FolioRefusals $refusals): RedirectResponse
    {
        $result = $reverseLine($request->user(), $line);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Line reversed.')]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Quote a discount for the folio. It writes nothing.
     */
    public function quoteDiscount(QuoteFolioDiscountRequest $request, Property $property, Event $event, Folio $folio, DiscountQuote $discountQuote, CurrentBusinessDate $currentBusinessDate, FolioRefusals $refusals): JsonResponse
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
     * Apply a quoted discount. A refusal goes back to the Dialog as an error.
     */
    public function storeDiscount(StoreFolioDiscountRequest $request, Property $property, Event $event, Folio $folio, ApplyDiscount $applyDiscount, FolioRefusals $refusals): RedirectResponse
    {
        $discount = $this->discount($property, $request->integer('discount_id'));
        $result = $applyDiscount($request->user(), $folio, $discount, $request->amount(), $request->string('fingerprint')->toString());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $event))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discount :name applied.', ['name' => $discount->name])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Remove a discount from its charge. A refusal is an error toast.
     */
    public function destroyDiscount(Request $request, Property $property, Event $event, Folio $folio, FolioLine $line, RemoveDiscount $removeDiscount, FolioRefusals $refusals): RedirectResponse
    {
        $result = $removeDiscount($request->user(), $line);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Discount removed.')]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Close the folio at 0.00. Closing completes a Confirmed event. A refusal is an error toast.
     */
    public function close(Request $request, Property $property, Event $event, Folio $folio, CloseFolio $closeFolio, FolioRefusals $refusals): RedirectResponse
    {
        $result = $closeFolio($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal, $result->rooms)]
            : ['type' => 'success', 'message' => __('Folio :number closed.', ['number' => $folio->number])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Reopen a Closed folio (`folio.reopen`, checked by `FolioReopenRule`); anyone else gets a 403.
     */
    public function reopen(Request $request, Property $property, Event $event, Folio $folio, ReopenFolio $reopenFolio, FolioRefusals $refusals, FolioReopenRule $reopenRule): RedirectResponse
    {
        abort_unless($reopenRule->allows($request->user(), $folio), 403);

        $result = $reopenFolio($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Folio :number reopened.', ['number' => $folio->number])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Void the folio's AR invoice and reopen the folio in one step. A refusal rolls both back and is an error toast.
     */
    public function voidInvoiceAndReopen(Request $request, Property $property, Event $event, Folio $folio, VoidInvoiceAndReopen $voidInvoiceAndReopen, FolioRefusals $refusals, ArInvoiceRefusals $invoiceRefusals, FolioReopenRule $reopenRule): RedirectResponse
    {
        $invoiceNumber = $reopenRule->billingInvoice($folio)?->number;
        $result = $voidInvoiceAndReopen($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => in_array($result->refusal, [ArInvoiceResult::REFUSAL_NOT_VOIDABLE, ArInvoiceResult::REFUSAL_ALREADY_VOID], true) ? $invoiceRefusals->message((string) $result->refusal) : $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __('Invoice :number voided. The folio is open again.', ['number' => $invoiceNumber])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * Move the folio's balance to the city ledger and issue its invoice. A refusal is an error toast.
     */
    public function settleCityLedger(Request $request, Property $property, Event $event, Folio $folio, SettleToCityLedger $settleToCityLedger, ArInvoiceRefusals $refusals): RedirectResponse
    {
        $result = $settleToCityLedger($request->user(), $folio);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal, $result->rooms)]
            : ['type' => 'success', 'message' => __('Settled to City ledger. Invoice :number issued.', ['number' => $result->invoice?->number])]);

        return back(fallback: $this->page($property, $event));
    }

    /**
     * A voucher for the posted lines in `?lines[]=`, as an A4 PDF shown inline. An event folio has no Scheduled rows
     * yet, so `?scheduled[]=` is a 404.
     */
    public function voucher(FolioVoucherRequest $request, Property $property, Event $event, Folio $folio, FolioVoucher $voucher, PrintedPdf $printedPdf): Response
    {
        $ids = $request->lineIds();

        abort_unless($request->scheduledKeys() === [], 404);
        abort_unless($folio->lines()->whereIn('id', $ids)->count() === count($ids), 404);
        abort_unless($voucher->printableLines($folio)->whereIn('id', $ids)->count() === count($ids), 422);

        return $printedPdf->make($property, 'pdf.voucher', ['voucher' => $voucher->forLines($folio, $ids)])
            ->stream('voucher-'.$folio->number.'.pdf');
    }

    /**
     * The invoice of a Closed folio as an A4 PDF shown inline. An Open or Voided folio is a 404.
     */
    public function invoice(Property $property, Event $event, Folio $folio, FolioInvoice $folioInvoice, PrintedPdf $printedPdf): Response
    {
        abort_unless($folio->status === FolioStatus::Closed && $folio->invoice_number !== null, 404);

        return $printedPdf->make($property, 'pdf.folio-invoice', [
            'invoice' => $folioInvoice->for($folio),
            'settings' => $property->printSetting,
        ])->stream('invoice-'.$folio->invoice_number.'.pdf');
    }

    private function discount(Property $property, int $id): Discount
    {
        return Discount::query()->where('property_id', $property->id)->active()->findOrFail($id);
    }

    private function page(Property $property, Event $event): string
    {
        return route('events.show', ['property' => $property, 'event' => $event]);
    }
}
