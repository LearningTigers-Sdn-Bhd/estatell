<?php

namespace App\Actions\BookingEngine;

use App\Models\GatewayPayment;

/**
 * What `StartBookingHoldPayment` returns: the order to open the checkout with, or a stable refusal code with a message
 * that is safe to show.
 */
class BookingHoldPaymentResult
{
    public const string REFUSAL_PROPERTY_NOT_FOUND = 'property_not_found';

    public const string REFUSAL_HOLD_NOT_OPEN = 'hold_not_open';

    public const string REFUSAL_PAYMENT_UNAVAILABLE = 'payment_unavailable';

    private function __construct(
        public readonly ?GatewayPayment $payment,
        public readonly ?string $refusal,
        public readonly ?string $message,
    ) {}

    public static function started(GatewayPayment $payment): self
    {
        return new self($payment, null, null);
    }

    public static function refused(string $refusal, string $message): self
    {
        return new self(null, $refusal, $message);
    }
}
