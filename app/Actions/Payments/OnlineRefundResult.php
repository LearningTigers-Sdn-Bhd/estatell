<?php

namespace App\Actions\Payments;

use App\Models\GatewayRefund;

/**
 * What `RequestOnlineRefund` and `RetryOnlineRefund` return: the refund row, or a `PaymentResult` refusal code. A repeated
 * key returns the existing row with `replayed` set and asks Curlec nothing.
 */
class OnlineRefundResult
{
    private function __construct(
        public readonly ?GatewayRefund $refund,
        public readonly bool $replayed,
        public readonly ?string $refusal,
    ) {}

    public static function requested(GatewayRefund $refund): self
    {
        return new self($refund, false, null);
    }

    public static function replayed(GatewayRefund $refund): self
    {
        return new self($refund, true, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, false, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
