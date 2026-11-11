<?php

namespace App\Actions\Concierge;

use App\Models\GatewayPayment;

/**
 * What `StartStayPayment` returns: the order to open the checkout with, or a refusal code with a message that is safe to
 * show the guest.
 */
final readonly class StayPaymentResult
{
    public const string REFUSAL_NOT_OPEN = 'stay_not_open';

    public const string REFUSAL_NOTHING_DUE = 'nothing_due';

    public const string REFUSAL_UNAVAILABLE = 'payment_unavailable';

    private function __construct(
        public ?GatewayPayment $payment,
        public ?string $refusal,
        public ?string $message,
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
