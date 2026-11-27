<?php

namespace App\Enums;

use ValueError;

/**
 * What happened to a room or bed booking on Rooms on books › Changes: booked, cancelled, its dates changed, or marked
 * as a no-show. The case order is the section order. See docs/22-reports/05-rooms-on-books.md.
 */
enum BookingChangeKind: string
{
    case NewBooking = 'new';
    case Cancellation = 'cancellation';
    case Amendment = 'amendment';
    case NoShow = 'no-show';

    public function label(): string
    {
        return match ($this) {
            self::NewBooking => __('New booking'),
            self::Cancellation => __('Cancellation'),
            self::Amendment => __('Amendment'),
            self::NoShow => __('No-show'),
        };
    }

    /**
     * The section heading and the Overview impact row.
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::NewBooking => __('New bookings'),
            self::Cancellation => __('Cancellations'),
            self::Amendment => __('Amendments'),
            self::NoShow => __('No-shows'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::NewBooking => Tone::Success,
            self::Cancellation, self::NoShow => Tone::Danger,
            self::Amendment => Tone::Info,
        };
    }

    /**
     * The activity log event the change is read from, or null for a new booking (read from the units).
     */
    public function activityEvent(): ?string
    {
        return match ($this) {
            self::NewBooking => null,
            self::Cancellation => 'reservation.unit_cancelled',
            self::Amendment => 'reservation.unit_dates_changed',
            self::NoShow => 'reservation.unit_no_show',
        };
    }

    public static function fromActivityEvent(string $event): self
    {
        foreach (self::cases() as $kind) {
            if ($kind->activityEvent() === $event) {
                return $kind;
            }
        }

        throw new ValueError("No booking change for the event {$event}.");
    }
}
