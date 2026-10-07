<?php

namespace App\Services\Folio;

use Brick\Money\Money;

/**
 * One discount child line of a quoted night charge. `amount` is positive and posts negated. A null
 * `transactionCodeId` is the stay discount, which uses the property's system discount code.
 */
class QuotedDiscount
{
    public function __construct(
        public readonly string $key,
        public readonly string $description,
        public readonly Money $amount,
        public readonly ?int $transactionCodeId,
    ) {}
}
