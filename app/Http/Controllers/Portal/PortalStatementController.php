<?php

namespace App\Http\Controllers\Portal;

use App\Data\CityLedger\ArInvoiceRowData;
use App\Data\Portal\PortalPaymentRowData;
use App\Data\Property\CurrencyData;
use App\Http\Controllers\CityLedger\CompanyStatementController;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\ArInvoicePrinter;
use App\Services\CityLedger\ArPaymentReceipt;
use App\Services\CityLedger\CompanyBillTo;
use App\Services\Portal\PortalStatements;
use App\Services\Property\PrintedPdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The company's statements at the property: the statement of account, its invoices and payments, and their PDFs. The
 * company always comes from the linked company of the `portal` middleware, never from the URL. Every document is
 * looked up through that company at the property, so another company's or property's document is a 404.
 * See docs/23-company-portal/01-company-portal.md#statements.
 */
class PortalStatementController extends Controller
{
    /**
     * The statements page: the statement with its aging for the `from` and `to` dates, the invoices and the payments.
     */
    public function show(Request $request, Property $property, PortalStatements $statements, ArInvoiceDirectory $directory): Response
    {
        $company = $this->company($request);
        $property->loadMissing('settings');
        [$from, $to] = CompanyStatementController::range($request, $directory->today($property));

        return Inertia::render('portal/statements', [
            'propertyCode' => $property->code,
            'currency' => CurrencyData::forProperty($property),
            'statement' => $statements->statement($company, $from, $to),
            'invoices' => ArInvoiceRowData::collect($statements->invoices($property, $company), PaginatedDataCollection::class),
            'payments' => PortalPaymentRowData::collect($statements->payments($property, $company), PaginatedDataCollection::class),
        ]);
    }

    /**
     * The statement of account as an A4 PDF for the same dates as the page.
     */
    public function pdf(Request $request, Property $property, PortalStatements $statements, ArInvoiceDirectory $directory, CompanyBillTo $billTo, PrintedPdf $printedPdf): HttpResponse
    {
        $company = $this->company($request);
        [$from, $to] = CompanyStatementController::range($request, $directory->today($property));
        $statement = $statements->statement($company, $from, $to);

        return $printedPdf->make($property, 'pdf.ar-statement', [
            'statement' => $statement,
            'company' => $company,
            'billToLines' => $billTo->lines($company),
            'currency' => $property->settings?->currency->getCurrencyCode() ?? '',
        ])
            ->stream('statement-'.str($company->name)->slug().'-'.$statement->to.'.pdf');
    }

    /**
     * An invoice or credit note as the staff PDF. Void documents are a 404.
     */
    public function invoicePdf(Request $request, Property $property, int $invoice, PortalStatements $statements, ArInvoicePrinter $printer, PrintedPdf $printedPdf): HttpResponse
    {
        $arInvoice = $statements->invoice($property, $this->company($request), $invoice);
        abort_if($arInvoice->migrated, 404);

        $document = $printer->forInvoice($arInvoice);

        return $printedPdf->make($property, 'pdf.ar-invoice', ['document' => $document, 'settings' => $property->printSetting])
            ->stream('ar-'.$document->number.'.pdf');
    }

    /**
     * A payment receipt as the staff PDF. Voided payments are a 404.
     */
    public function receipt(Request $request, Property $property, int $payment, PortalStatements $statements, ArPaymentReceipt $arPaymentReceipt, PrintedPdf $printedPdf): HttpResponse
    {
        $arPayment = $statements->payment($property, $this->company($request), $payment);
        abort_unless($arPayment->hasReceipt(), 404);

        $receipt = $arPaymentReceipt->forPayment($arPayment);

        return $printedPdf->make($property, 'pdf.ar-receipt', ['receipt' => $receipt])
            ->stream('receipt-'.$receipt->receiptNumber.'.pdf');
    }

    /**
     * The company of the active link that `EnsurePortalAccess` checked.
     */
    private function company(Request $request): Company
    {
        $link = $request->attributes->get('portalLink');

        abort_unless($link instanceof CompanyUser, 404);

        return $link->company;
    }
}
