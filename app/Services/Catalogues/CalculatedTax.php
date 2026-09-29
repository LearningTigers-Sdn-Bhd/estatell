<?php

namespace App\Services\Catalogues;

use Brick\Money\Money;

class CalculatedTax
{
    public function __construct(
        public readonly int $taxId,
        public readonly int $transactionCodeId,
        public readonly string $name,
        public readonly Money $amount,
        public readonly bool $inclusive,
    ) {}
}
