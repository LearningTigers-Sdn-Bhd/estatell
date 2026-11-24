<?php

namespace App\Services\Pos\Calculation;

use App\Enums\PosDiscountType;

/**
 * A discount to work out: a percent (0 to 100), an amount, or the price to charge. `Foc` ignores the value.
 */
final readonly class PosCalculatorDiscount
{
    public function __construct(public PosDiscountType $type, public string $value = '0') {}
}
