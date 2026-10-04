<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * The invoice page: the row, where it came from, its credit notes and the folio lines it was made from. `canVoid`
 * and `canCreditNote` already include the permission and the state, so the page never works them out.
 */
class ArInvoiceData extends Data
{
    /**
     * @param  list<ArInvoiceRowData>  $creditNotes
     * @param  list<ArInvoiceLineData>  $lines
     * @param  list<ArAllocationRowData>  $allocations
     */
    public function __construct(
        public ArInvoiceRowData $row,
        public ?string $reason,
        public ?string $creditForNumber,
        public ?int $creditForId,
        public ?string $folioNumber,
        public ?int $reservationId,
        public ?string $reservationReference,
        public string $credited,
        public array $creditNotes,
        public array $lines,
        public array $allocations,
        public bool $canVoid,
        public bool $canCreditNote,
    ) {}
}
