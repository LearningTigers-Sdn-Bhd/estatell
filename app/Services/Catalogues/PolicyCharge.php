<?php

namespace App\Services\Catalogues;

use Brick\Money\Money;

/**
 * What a room revenue policy charges for one unit, before tax. A zero amount means there is nothing to post.
 */
class PolicyCharge
{
    public function __construct(
        public readonly Money $amount,
        public readonly bool $withinFreeWindow = false,
    ) {}

    public function isFree(): bool
    {
        return $this->amount->isZero();
    }
}
