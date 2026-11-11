<?php

namespace App\Services\BookingEngine;

/**
 * What a guest would pay and get back if they cancel now, or why they cannot cancel online. The refund is the amount paid
 * less the fee and the tax on the fee. Amounts are decimal strings.
 */
class OnlineCancellationQuote
{
    public const string REFUSAL_ALREADY_CANCELLED = 'already_cancelled';

    public const string REFUSAL_ALREADY_ARRIVED = 'already_arrived';

    public const string REFUSAL_CONTACT_PROPERTY = 'contact_property';

    /**
     * @param  array<int, string>  $fees  The fee before tax of each unit, by unit id.
     */
    private function __construct(
        public readonly ?string $refusal,
        public readonly string $paid,
        public readonly string $fee,
        public readonly string $tax,
        public readonly string $refund,
        public readonly array $fees,
    ) {}

    /**
     * @param  array<int, string>  $fees
     */
    public static function open(string $paid, string $fee, string $tax, string $refund, array $fees): self
    {
        return new self(null, $paid, $fee, $tax, $refund, $fees);
    }

    public static function closed(string $refusal): self
    {
        return new self($refusal, '0.00', '0.00', '0.00', '0.00', []);
    }

    public function canCancel(): bool
    {
        return $this->refusal === null;
    }

    public function message(): ?string
    {
        return match ($this->refusal) {
            null => null,
            self::REFUSAL_ALREADY_CANCELLED => __('This booking is already cancelled.'),
            self::REFUSAL_ALREADY_ARRIVED => __('Online cancellation has closed for this booking. Please contact the property.'),
            default => __('This booking cannot be cancelled online. Please contact the property.'),
        };
    }
}
