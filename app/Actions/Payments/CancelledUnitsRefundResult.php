<?php

namespace App\Actions\Payments;

/**
 * What `RefundCancelledUnits` returns: the amount asked of Curlec, or a `PaymentResult` refusal code.
 */
class CancelledUnitsRefundResult
{
    private function __construct(
        public readonly string $amount,
        public readonly ?string $refusal,
    ) {}

    public static function requested(string $amount): self
    {
        return new self($amount, null);
    }

    public static function refused(string $refusal): self
    {
        return new self('0.00', $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
