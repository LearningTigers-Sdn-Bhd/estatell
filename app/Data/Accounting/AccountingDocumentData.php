<?php

namespace App\Data\Accounting;

use App\Data\Shared\BadgeData;
use App\Models\AccountingDocument;
use Spatie\LaravelData\Data;

/**
 * One company invoice, credit note or payment as sent to the accounting software, for the History tab.
 */
class AccountingDocumentData extends Data
{
    public function __construct(
        public int $id,
        public string $kindLabel,
        public string $number,
        public string $companyName,
        public string $amount,
        public string $date,
        public BadgeData $status,
        public ?string $externalId,
        public ?string $error,
    ) {}

    public static function fromModel(AccountingDocument $document): self
    {
        if ($document->ar_invoice_id !== null) {
            $invoice = $document->arInvoice;

            return new self(
                id: $document->id,
                kindLabel: $document->kind->label(),
                number: $invoice->number,
                companyName: $invoice->company->name,
                amount: (string) abs((float) $invoice->amount),
                date: $invoice->issued_on->toDateString(),
                status: BadgeData::fromAccountingDocumentStatus($document->status),
                externalId: $document->external_id,
                error: $document->error,
            );
        }

        $payment = $document->arPayment;

        return new self(
            id: $document->id,
            kindLabel: $document->kind->label(),
            number: $payment->displayNumber(),
            companyName: $payment->company->name,
            amount: $payment->amount,
            date: $payment->received_on->toDateString(),
            status: BadgeData::fromAccountingDocumentStatus($document->status),
            externalId: $document->external_id,
            error: $document->error,
        );
    }
}
