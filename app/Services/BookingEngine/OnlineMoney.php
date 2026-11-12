<?php

namespace App\Services\BookingEngine;

/**
 * What `OnlineBookingMoney` returns. Amounts are decimal strings.
 */
final readonly class OnlineMoney
{
    /**
     * @param  list<OnlineRefund>  $refunds  Processed refunds, oldest first.
     */
    public function __construct(
        public int $gatewayPaymentId,
        public string $amount,
        public ?string $curlecFee,
        public ?string $bookedRoomTotal,
        public array $refunds,
    ) {}
}
