<?php

namespace App\Services\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use App\Services\Folio\Letterhead;
use App\Services\Guests\CountryOptions;
use Carbon\CarbonImmutable;

/**
 * Reads an AR invoice or credit note and gathers what its PDF shows: the property letterhead, the company's name,
 * tax numbers and billing address, the folio lines (an invoice) or the reason and the invoice it corrects (a credit
 * note) and the total. It writes nothing. See docs/15-city-ledger/02-ar-invoices.md#pdf.
 */
class ArInvoicePrinter
{
    public function __construct(private Letterhead $letterhead, private ArInvoiceDetail $detail, private CountryOptions $countryOptions) {}

    public function forInvoice(ArInvoice $invoice): ArInvoiceDocument
    {
        $invoice->loadMissing(['property.settings', 'company', 'folio', 'creditFor:id,number']);
        $company = $invoice->company;
        $letterhead = $this->letterhead->for($invoice->property);
        $isInvoice = $invoice->type === ArInvoiceType::Invoice;

        return new ArInvoiceDocument(
            title: $invoice->type->label(),
            number: $invoice->number,
            propertyName: $letterhead['name'],
            addressLines: $letterhead['addressLines'],
            contactLines: $letterhead['contactLines'],
            registrationLines: $letterhead['registrationLines'],
            companyName: $company->name,
            billToLines: array_values(array_filter([
                $company->billing_address_line1,
                $company->billing_address_line2,
                trim(($company->billing_postal_code ?? '').' '.($company->billing_city ?? '').' '.($company->billing_state_code ?? '')),
                $this->countryOptions->name($company->billing_country_code),
                filled($company->brn) ? __('Registration no. :number', ['number' => $company->brn]) : null,
                filled($company->tin) ? __('TIN :number', ['number' => $company->tin]) : null,
                filled($company->sst_registration_number) ? __('SST no. :number', ['number' => $company->sst_registration_number]) : null,
            ], filled(...))),
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
