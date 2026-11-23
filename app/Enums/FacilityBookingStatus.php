<?php

namespace App\Enums;

/**
 * The status of a facility booking. An event's bookings follow the event: Tentative holds without blocking,
 * Confirmed blocks. See docs/33-facilities/04-facility-bookings.md#enums.
 */
enum FacilityBookingStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Tentative => __('Tentative'),
            self::Confirmed => __('Confirmed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Tentative => Tone::Warning,
            self::Confirmed => Tone::Info,
            self::Cancelled => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Tentative => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Cancelled],
            self::Cancelled => [],
        }, true);
    }

    /** Whether the booking still takes its facility (shown, and counted by the clash check when Confirmed). */
    public function isLive(): bool
    {
        return $this !== self::Cancelled;
    }
}
