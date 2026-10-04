<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * One folio line an invoice was made from, read only.
 */
class ArInvoiceLineData extends Data
{
    public function __construct(
        public string $postingDate,
        public string $description,
        public string $amount,
    ) {}
}
