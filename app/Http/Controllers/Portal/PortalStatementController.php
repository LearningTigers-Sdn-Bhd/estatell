<?php

namespace App\Http\Controllers\Portal;

use App\Data\CityLedger\ArInvoiceRowData;
use App\Data\CityLedger\StatementData;
use App\Data\Portal\PortalAccountSummaryData;
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
use App\Services\Portal\PortalHowToPay;
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
     * The Statement tab: the statement with its aging and payments on bookings for the `from` and `to` dates.
     */
    public function show(Request $request, Property $property, PortalStatements $statements, ArInvoiceDirectory $directory, PortalHowToPay $howToPay): Response
    {
        return $this->renderTab($request, $property, 'statement', $statements, $directory, $howToPay, fn (Company $company, StatementData $statement): array => [
            'statement' => $statement,
        ]);
    }

    /**
     * The Invoices tab: the company's Open and Settled invoices and credit notes, 25 per page.
     */
    public function invoices(Request $request, Property $property, PortalStatements $statements, ArInvoiceDirectory $directory, PortalHowToPay $howToPay): Response
    {
        return $this->renderTab($request, $property, 'invoices', $statements, $directory, $howToPay, fn (Company $company): array => [
            'invoices' => ArInvoiceRowData::collect($statements->invoices($property, $company), PaginatedDataCollection::class),
        ]);
    }

    /**
     * The Payments tab: the company's payments that are not void, 25 per page.
     */
    public function payments(Request $request, Property $property, PortalStatements $statements, ArInvoiceDirectory $directory, PortalHowToPay $howToPay): Response
    {
        return $this->renderTab($request, $property, 'payments', $statements, $directory, $howToPay, fn (Company $company): array => [
            'payments' => PortalPaymentRowData::collect($statements->payments($property, $company), PaginatedDataCollection::class),
        ]);
    }

    /**
     * The Slips tab: the payment slips the company sent, newest first.
     */
    public function slips(Request $request, Property $property, PortalStatements $statements, ArInvoiceDirectory $directory, PortalHowToPay $howToPay): Response
    {
        return $this->renderTab($request, $property, 'slips', $statements, $directory, $howToPay, fn (Company $company): array => [
            'paymentSlips' => $statements->slips($property, $company),
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
     * Each tab has its own URL. Every tab gets the summary (as at the statement's end date, from the `from` and `to`
     * dates), whether the company can send a payment and the slips still waiting; only the open tab's list is read.
     *
     * @param  'statement'|'invoices'|'payments'|'slips'  $tab
     * @param  callable(Company, StatementData): array<string, mixed>  $tabProps
     */
    private function renderTab(Request $request, Property $property, string $tab, PortalStatements $statements, ArInvoiceDirectory $directory, PortalHowToPay $howToPay, callable $tabProps): Response
    {
        $company = $this->company($request);
        $property->loadMissing('settings');
        [$from, $to] = CompanyStatementController::range($request, $directory->today($property));
        $statement = $statements->statement($company, $from, $to);
        $canSendSlip = $statements->canSendSlip($company);

        return Inertia::render('portal/statements', [
            'tab' => $tab,
            'propertyCode' => $property->code,
            'currency' => CurrencyData::forProperty($property),
            'summary' => PortalAccountSummaryData::fromStatement($statement),
            'slipsWaiting' => $statements->slipsWaiting($company),
            'canSendSlip' => $canSendSlip,
            'bankAccounts' => $canSendSlip ? $howToPay->bankAccounts($property) : [],
            ...$tabProps($company, $statement),
        ]);
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
