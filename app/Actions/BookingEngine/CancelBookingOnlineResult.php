<?php

namespace App\Actions\BookingEngine;

use App\Services\BookingEngine\OnlineCancellationQuote;

/**
 * What `CancelBookingOnline` returns: the refund asked of Curlec, or a stable refusal code with its message.
 */
class CancelBookingOnlineResult
{
    public const string REFUSAL_REFUND_CHANGED = 'refund_changed';

    private function __construct(
        public readonly ?string $refund,
        public readonly ?string $refusal,
        public readonly ?string $message,
        public readonly ?string $currentRefund = null,
    ) {}

    public static function done(string $refund): self
    {
        return new self($refund, null, null);
    }

    public static function closed(OnlineCancellationQuote $quote): self
    {
        return new self(null, $quote->refusal, $quote->message());
    }

    public static function refundChanged(string $currentRefund): self
    {
        return new self(null, self::REFUSAL_REFUND_CHANGED, __('The refund has changed. Check the new amount before you cancel.'), $currentRefund);
    }

    public static function refused(): self
    {
        return new self(null, OnlineCancellationQuote::REFUSAL_CONTACT_PROPERTY, __('This booking cannot be cancelled online. Please contact the property.'));
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
