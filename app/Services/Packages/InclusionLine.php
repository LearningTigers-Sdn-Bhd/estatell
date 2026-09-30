<?php

namespace App\Services\Packages;

use Brick\Money\Money;

/** One inclusion's share of a night: the charge item it posts to and the amount moved off the room. */
class InclusionLine
{
    public function __construct(
        public readonly int $inclusionId,
        public readonly int $chargeItemId,
        public readonly Money $amount,
    ) {}
}
