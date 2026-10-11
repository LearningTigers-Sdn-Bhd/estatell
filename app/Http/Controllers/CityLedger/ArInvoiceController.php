<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\IssueCreditNote;
use App\Actions\CityLedger\VoidArInvoice;
use App\Data\CityLedger\ArInvoiceRowData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\IssueCreditNoteRequest;
use App\Models\ArInvoice;
use App\Models\Property;
use App\Services\CityLedger\ArInvoiceDetail;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\ArInvoicePrinter;
use App\Services\CityLedger\ArInvoiceRefusals;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ArInvoiceController extends Controller
{
    /**
     * Every invoice and credit note of the property with search, type and status filters in the URL.
     */
    public function index(Request $request, Property $property, ArInvoiceDirectory $directory): Response
    {
        return Inertia::render('finance/city-ledger/invoices/index', [
            'invoices' => ArInvoiceRowData::collect($directory->paginate($property, $request), PaginatedDataCollection::class),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'type' => $request->string('filter.type')->toString(),
                'status' => $request->string('filter.status')->toString(),
            ],
            'sort' => $request->string('sort')->toString(),
            'types' => ArInvoiceType::options(),
            'statuses' => ArInvoiceStatus::options(),
            'currency' => $property->settings?->currency,
        ]);
    }

    public function show(Request $request, Property $property, ArInvoice $invoice, ArInvoiceDetail $detail): Response
    {
        return Inertia::render('finance/city-ledger/invoices/show', [
            'invoice' => $detail->build($invoice, $request->user()),
            'currency' => $property->settings?->currency,
        ]);
    }

    /**
     * The invoice or credit note as an A4 PDF, shown inline. It is rendered on demand and not stored.
     */
    public function pdf(Property $property, ArInvoice $invoice, ArInvoicePrinter $printer, PrintedPdf $printedPdf): HttpResponse
    {
        $document = $printer->forInvoice($invoice);

        return $printedPdf->make($property, 'pdf.ar-invoice', ['document' => $document, 'settings' => $property->printSetting])
            ->stream('ar-'.$document->number.'.pdf');
    }

    /**
     * Issue a credit note against an invoice. A refusal goes back to the Dialog as an error on the matching field.
     */
    public function storeCreditNote(IssueCreditNoteRequest $request, Property $property, ArInvoice $invoice, IssueCreditNote $issueCreditNote, ArInvoiceRefusals $refusals): RedirectResponse
    {
        $result = $issueCreditNote($request->user(), $invoice, $request->string('amount')->toString(), $request->string('reason')->toString());

        if ($result->isRefused()) {
            return back(fallback: $this->page($property, $invoice))->withErrors($refusals->errors((string) $result->refusal));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credit note :number issued.', ['number' => $result->invoice?->number])]);

        return back(fallback: $this->page($property, $invoice));
    }

    /**
     * Void an invoice (`city-ledger.invoice`) or a credit note (`city-ledger.credit-note`).
     */
    public function void(Request $request, Property $property, ArInvoice $invoice, VoidArInvoice $voidArInvoice, ArInvoiceRefusals $refusals): RedirectResponse
    {
        abort_unless($request->user()->can($invoice->type === ArInvoiceType::Invoice ? PermissionEnum::CityLedgerInvoice->value : PermissionEnum::CityLedgerCreditNote->value), 403);

        $result = $voidArInvoice($request->user(), $invoice);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $refusals->message((string) $result->refusal)]
            : ['type' => 'success', 'message' => __(':number voided.', ['number' => $invoice->number])]);

        return back(fallback: $this->page($property, $invoice));
    }

    private function page(Property $property, ArInvoice $invoice): string
    {
        return route('finance.city-ledger.invoices.show', ['property' => $property, 'invoice' => $invoice]);
    }
}
