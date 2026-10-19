<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * A live tax line under a charge, for the Remove tax Dialog: the tax line's id, the tax it came from and its amount.
 */
class ManualLineTaxData extends Data
{
    public function __construct(
        public int $lineId,
        public ?int $taxId,
        public string $name,
        public string $amount,
    ) {}
}
