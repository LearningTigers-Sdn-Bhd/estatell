<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * How much of a refund goes back through one of the order's original payments.
 */
class PosRefundPayoutInputData extends Data
{
    public function __construct(public int $paymentId, public string $amount) {}
}
