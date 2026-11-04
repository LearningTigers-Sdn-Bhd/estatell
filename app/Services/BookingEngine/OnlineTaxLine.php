<?php

namespace App\Services\BookingEngine;

use Brick\Money\Money;

/**
 * One tax of a stay, named from the tax record. An inclusive tax is already part of the price.
 */
class OnlineTaxLine
{
    public function __construct(
        public readonly string $name,
        public readonly Money $amount,
        public readonly bool $inclusive,
    ) {}
}
