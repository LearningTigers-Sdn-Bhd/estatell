<?php

namespace App\Services\BookingEngine;

/**
 * A processed Curlec refund of an online booking.
 */
final readonly class OnlineRefund
{
    public function __construct(
        public int $gatewayRefundId,
        public string $amount,
    ) {}
}
