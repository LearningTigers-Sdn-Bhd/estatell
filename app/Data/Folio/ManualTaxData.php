<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One tax of a charge row in the manual folio preview. An `exempt` tax is a foreign-only tax that a local guest does
 * not pay: it shows 0.00.
 */
class ManualTaxData extends Data
{
    public function __construct(
        public string $name,
        public string $amount,
        public bool $exempt,
    ) {}
}
