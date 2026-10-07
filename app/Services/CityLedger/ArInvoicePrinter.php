<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use Carbon\CarbonImmutable;

/**
 * Reads an AR invoice or credit note and gathers what its PDF shows: the company's name,
 * tax numbers and billing address, the folio lines (an invoice) or the reason and the invoice it corrects (a credit
 * note) and the total. It writes nothing. See docs/15-city-ledger/02-ar-invoices.md#pdf.
 */
class ArInvoicePrinter
{
    public function __construct(private ArInvoiceDetail $detail, private CompanyBillTo $billTo) {}

    public function forInvoice(ArInvoice $invoice): ArInvoiceDocument
    {
        $invoice->loadMissing(['property.settings', 'company', 'folio', 'creditFor:id,number']);
        $company = $invoice->company;
        $isInvoice = $invoice->type === ArInvoiceType::Invoice;

        return new ArInvoiceDocument(
            title: $invoice->type->label(),
            number: $invoice->number,
            companyName: $company->name,
            billToLines: $this->billTo->lines($company),
            issuedOn: $invoice->issued_on->format('j M Y'),
            dueOn: $invoice->due_on?->format('j M Y'),
            folioNumber: $invoice->folio?->number,
            creditFor: $invoice->creditFor?->number,
            reason: $invoice->reason,
            currency: $invoice->property->settings?->currency->value ?? '',
            lines: $isInvoice
                ? array_map(fn ($line): array => [CarbonImmutable::parse($line->postingDate)->format('j M Y'), $line->description, $line->amount], $this->detail->lines($invoice))
                : [],
            total: (string) $invoice->amount,
            isVoid: $invoice->status === ArInvoiceStatus::Void,
        );
    }
}
