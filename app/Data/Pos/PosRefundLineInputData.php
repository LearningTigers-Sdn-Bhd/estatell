<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * One order line to refund and how many of it. `returnStock` puts the quantity back on the stock counts, and
 * `restoreMealEntitlement` gives back the included meal a covered line used.
 */
class PosRefundLineInputData extends Data
{
    public function __construct(
        public int $orderLineId,
        public int $quantity,
        public bool $returnStock = false,
        public bool $restoreMealEntitlement = false,
    ) {}
}
