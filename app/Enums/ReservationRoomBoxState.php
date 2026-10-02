<?php

namespace App\Enums;

/**
 * What the room box of a reservation shows. See docs/12-reservations/06-reservation-page.md.
 */
enum ReservationRoomBoxState: string
{
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case AutoAssign = 'auto_assign';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => __('Room assigned'),
            self::Unassigned => __('Room not assigned'),
            self::AutoAssign => __('Room assigned automatically'),
            self::Cancelled => __('Cancelled'),
            self::NoShow => __('No-show'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Unassigned => Tone::Warning,
            self::Assigned, self::AutoAssign, self::Cancelled, self::NoShow => Tone::Neutral,
        };
    }
}
