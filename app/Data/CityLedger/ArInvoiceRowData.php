<?php

namespace App\Data\CityLedger;

use App\Data\Shared\BadgeData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArInvoice;
use Spatie\LaravelData\Data;

/**
 * One row of the invoices list, and one credit note on the invoice page. `balance` is null for a credit note. An
 * invoice is `overdue` when it is Open with a balance, and its due date is before the business date.
 */
class ArInvoiceRowData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public int $companyId,
        public string $companyName,
        public ArInvoiceType $type,
        public string $typeLabel,
        public string $issuedOn,
        public ?string $dueOn,
        public string $amount,
        public ?string $balance,
        public BadgeData $status,
        public bool $overdue,
    ) {}

    /**
     * Expects `company` to be loaded. `$balance` comes from `ArInvoiceBalance` or the list query, `$today` is the
     * business date as `Y-m-d`.
     */
    public static function fromModel(ArInvoice $invoice, ?string $balance, string $today): self
    {
        $isInvoice = $invoice->type === ArInvoiceType::Invoice;

        return new self(
            id: $invoice->id,
            number: $invoice->number,
            companyId: $invoice->company_id,
            companyName: $invoice->company->name,
            type: $invoice->type,
            typeLabel: $invoice->type->label(),
            issuedOn: $invoice->issued_on->toDateString(),
            dueOn: $invoice->due_on?->toDateString(),
            amount: $invoice->amount,
            balance: $isInvoice ? $balance : null,
            status: BadgeData::fromArInvoiceStatus($invoice->status),
            overdue: $isInvoice
                && $invoice->status === ArInvoiceStatus::Open
                && $balance !== null
                && bccomp($balance, '0', 2) > 0
                && $invoice->due_on !== null
                && $invoice->due_on->toDateString() < $today,
        );
    }
}
