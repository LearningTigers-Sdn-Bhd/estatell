<?php

namespace App\Services\Pos\Calculation;

use App\Enums\TaxRateType;

/**
 * One tax that applies to a line, as the property's tax catalogue defines it. Whether the line's price already holds the
 * tax is decided by the item, never by the tax row.
 */
final readonly class PosCalculatorTax
{
    public function __construct(
        public int $taxId,
        public int $transactionCodeId,
        public string $code,
        public string $name,
        public ?string $lhdnTaxType,
        public TaxRateType $rateType,
        public string $rate,
        public bool $foreignGuestsOnly = false,
    ) {}
}
