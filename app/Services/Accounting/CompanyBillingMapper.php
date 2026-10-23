<?php

namespace App\Services\Accounting;

use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Turns estatell company billing records into the requests the accounting software takes. It reads only. Invoices and
 * credit notes post to the city ledger clearing account (the account `DIRECT_BILL` is mapped to), with no tax: revenue and
 * tax are already in the daily journal, and the journal's city ledger debit lands on the same clearing account, so the
 * account nets to zero. See docs/26-accounting/02-company-billing.md.
 */
class CompanyBillingMapper
{
    public function currency(Property $property): string
    {
        return $property->loadMissing('settings')->settings->currency->getCurrencyCode();
    }

    public function customer(Company $company): CustomerDetails
    {
        return new CustomerDetails(
            code: "EST-{$company->id}",
            name: $company->name,
            email: $company->contact_email,
            phone: $company->contact_phone,
            addressLine1: $company->billing_address_line1,
            addressLine2: $company->billing_address_line2,
            city: $company->billing_city,
            postalCode: $company->billing_postal_code,
            countryCode: $company->billing_country_code,
            international: $company->billing_country_code !== null && strtoupper($company->billing_country_code) !== 'MY',
        );
    }

    public function invoice(Property $property, ArInvoice $invoice, string $customerId, string $accountCode): InvoiceDetails
    {
        return new InvoiceDetails(
            customerId: $customerId,
            reference: $invoice->number,
            date: CarbonImmutable::parse($invoice->issued_on->toDateString()),
            dueDate: CarbonImmutable::parse(($invoice->due_on ?? $invoice->issued_on)->toDateString()),
            currency: $this->currency($property),
            description: "{$property->name} folio {$invoice->number}",
            amount: $this->money($invoice->amount),
            accountCode: $accountCode,
        );
    }

    public function creditNote(Property $property, ArInvoice $note, string $customerId, string $accountCode, DocumentRef $invoice): CreditNoteDetails
    {
        return new CreditNoteDetails(
            customerId: $customerId,
            reference: $note->number,
            date: CarbonImmutable::parse($note->issued_on->toDateString()),
            currency: $this->currency($property),
            description: $note->reason ?? "Credit note {$note->number}",
            amount: $this->money(BigDecimal::of($note->amount)->abs()),
            accountCode: $accountCode,
            invoice: $invoice,
        );
    }

    /**
     * @param  list<ReceiptLink>  $links
     */
    public function receipt(Property $property, ArPayment $payment, string $customerId, string $accountCode, array $links): ReceiptDetails
    {
        $total = array_reduce($links, fn (BigDecimal $sum, ReceiptLink $link): BigDecimal => $sum->plus($link->amount), BigDecimal::zero());

        return new ReceiptDetails(
            customerId: $customerId,
            reference: $payment->receipt_number ?? throw new RuntimeException('An AR payment without a receipt number is not sent.'),
            date: CarbonImmutable::parse($payment->received_on->toDateString()),
            currency: $this->currency($property),
            description: "{$property->name} payment {$payment->receipt_number}",
            amount: $this->money($total),
            accountCode: $accountCode,
            links: $links,
        );
    }

    private function money(BigDecimal|string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(2);
    }
}
