<?php

namespace App\Services\Portal;

use App\Data\CityLedger\ArInvoiceRowData;
use App\Data\CityLedger\StatementData;
use App\Data\Portal\PortalPaymentRowData;
use App\Enums\ArInvoiceStatus;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\StatementBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reads the statements page of one company at one property: its Open and Settled AR documents, its payments that are
 * not void, and the statement of account from the staff city ledger. Every document is looked up by its id together
 * with the property and the company, so another company's or property's document is not found (404). It writes nothing.
 * See docs/23-company-portal/01-company-portal.md#statements.
 */
class PortalStatements
{
    public const string INVOICES_PAGE = 'invoices_page';

    public const string PAYMENTS_PAGE = 'payments_page';

    public function __construct(private StatementBuilder $statementBuilder, private ArInvoiceDirectory $directory) {}

    /**
     * The statement of account between two dates, with the aging as at the end date.
     */
    public function statement(Company $company, CarbonImmutable $from, CarbonImmutable $to): StatementData
    {
        return $this->statementBuilder->build($company, $from, $to);
    }

    /**
     * @return LengthAwarePaginator<int, ArInvoiceRowData>
     */
    public function invoices(Property $property, Company $company): LengthAwarePaginator
    {
        $today = $this->directory->today($property);
        $figures = $this->directory->balanceFigureSubqueries();

        return ArInvoice::query()
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereIn('status', [ArInvoiceStatus::Open->value, ArInvoiceStatus::Settled->value])
            ->addSelect('ar_invoices.*')
            ->selectSub($figures['credited'], 'credited')
            ->selectSub($figures['allocated'], 'allocated')
            ->with('company:id,name')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->paginate(ArInvoiceDirectory::PER_PAGE, ['*'], self::INVOICES_PAGE)
            ->withQueryString()
            ->through(fn (ArInvoice $invoice): ArInvoiceRowData => $this->directory->row($invoice, $today));
    }

    /**
     * @return LengthAwarePaginator<int, PortalPaymentRowData>
     */
    public function payments(Property $property, Company $company): LengthAwarePaginator
    {
        return ArPayment::query()
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->with('paymentMethod:id,name')
            ->orderByDesc('received_on')
            ->orderByDesc('id')
            ->paginate(ArInvoiceDirectory::PER_PAGE, ['*'], self::PAYMENTS_PAGE)
            ->withQueryString()
            ->through(fn (ArPayment $payment): PortalPaymentRowData => PortalPaymentRowData::fromModel($payment));
    }

    /**
     * One invoice or credit note the company can see. Void documents are not found.
     */
    public function invoice(Property $property, Company $company, int $invoiceId): ArInvoice
    {
        return ArInvoice::query()
            ->whereKey($invoiceId)
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereIn('status', [ArInvoiceStatus::Open->value, ArInvoiceStatus::Settled->value])
            ->firstOrFail();
    }

    /**
     * One payment the company can see. Voided payments are not found.
     */
    public function payment(Property $property, Company $company, int $paymentId): ArPayment
    {
        return ArPayment::query()
            ->whereKey($paymentId)
            ->where('property_id', $property->id)
            ->where('company_id', $company->id)
            ->whereNull('voided_at')
            ->firstOrFail();
    }
}
