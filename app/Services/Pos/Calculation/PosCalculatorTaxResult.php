<?php

namespace App\Services\Pos\Calculation;

/**
 * The tax one line owes, with the base it was worked out on and whether it sat inside the displayed price.
 */
final readonly class PosCalculatorTaxResult
{
    public function __construct(
        public PosCalculatorTax $tax,
        public string $taxableBase,
        public string $amount,
        public bool $inclusive,
    ) {}
}
