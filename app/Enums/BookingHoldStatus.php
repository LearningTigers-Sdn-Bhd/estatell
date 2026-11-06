<?php

namespace App\Enums;

/**
 * The status of a room hold during online checkout. Expired can still become Paid, because a bank can confirm late.
 * See docs/29-booking-engine/03-checkout-and-hold.md.
 */
enum BookingHoldStatus: string
{
    case Held = 'held';
    case Paid = 'paid';
    case Confirmed = 'confirmed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Held => __('Held'),
            self::Paid => __('Paid'),
            self::Confirmed => __('Confirmed'),
            self::Expired => __('Expired'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Held => Tone::Info,
            self::Paid => Tone::Warning,
            self::Confirmed => Tone::Success,
            self::Expired => Tone::Neutral,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Held => [self::Paid, self::Expired],
            self::Expired => [self::Paid],
            self::Paid => [self::Confirmed, self::Expired],
            self::Confirmed => [],
        }, true);
    }
}
