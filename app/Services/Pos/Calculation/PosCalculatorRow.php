<?php

namespace App\Services\Pos\Calculation;

use Brick\Math\BigDecimal;

/**
 * A line while the calculator works on it: gross, item discount, its share of the order discount, and what is left to pay.
 */
final class PosCalculatorRow
{
    public function __construct(
        public readonly PosCalculatorLine $line,
        public readonly BigDecimal $lineGross,
        public readonly BigDecimal $itemDiscount,
        public BigDecimal $share,
        public BigDecimal $payable,
    ) {}
}
