<?php

namespace App\Enums;

/**
 * What one row of an arrival import becomes. See docs/12-reservations/13-arrival-import.md#status.
 */
enum ArrivalImportOutcome: string
{
    case Booking = 'booking';
    case Hold = 'hold';
    case PastInHouse = 'past_in_house';
    case PastCheckedOut = 'past_checked_out';
    case Skipped = 'skipped';
    case AlreadyImported = 'already_imported';

    public function label(): string
    {
        return match ($this) {
            self::Booking => __('Booking'),
            self::Hold => __('Booking (hold)'),
            self::PastInHouse => __('Past stay, in house'),
            self::PastCheckedOut => __('Past stay, checked out'),
            self::Skipped => __('Skipped'),
            self::AlreadyImported => __('Already imported'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Booking => Tone::Info,
            self::Hold => Tone::Warning,
            self::PastInHouse => Tone::Success,
            self::PastCheckedOut => Tone::Neutral,
            self::Skipped, self::AlreadyImported => Tone::Neutral,
        };
    }

    /**
     * True when the row creates a unit.
     */
    public function imports(): bool
    {
        return ! in_array($this, [self::Skipped, self::AlreadyImported], true);
    }

    /**
     * True when the row is entered as a past stay.
     */
    public function isPastStay(): bool
    {
        return $this === self::PastInHouse || $this === self::PastCheckedOut;
    }
}
