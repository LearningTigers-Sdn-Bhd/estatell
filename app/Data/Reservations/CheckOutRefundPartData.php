<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One payment the Check out form refunds: its purpose and method, its reference when it has one, and the amount that
 * goes back, as 2-decimal text. The parts of a refund are listed in the order `CheckOutAndRefund` takes the money.
 */
class CheckOutRefundPartData extends Data
{
    public function __construct(
        public string $purpose,
        public string $method,
        public ?string $reference,
        public string $amount,
    ) {}
}
