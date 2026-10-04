<?php

namespace App\Data\CityLedger;

use App\Models\ArInvoice;
use Spatie\LaravelData\Data;

/**
 * An invoice of the company that still owes money, for the Allocate Dialog.
 */
class OpenArInvoiceData extends Data
{
    public function __construct(
        public int $id,
        public string $number,
        public ?string $dueOn,
        public string $balance,
    ) {}

    public static function fromModel(ArInvoice $invoice, string $balance): self
    {
        return new self($invoice->id, $invoice->number, $invoice->due_on?->toDateString(), $balance);
    }
}
