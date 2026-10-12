<?php

namespace App\Enums;

/**
 * Why a Dirty room needs cleaning. It is worked out from the room's units each time the board loads, never stored.
 * See docs/19-housekeeping/01-room-status-board.md.
 */
enum HousekeepingReason: string
{
    case Departed = 'departed';
    case StayOver = 'stay_over';

    public function label(): string
    {
        return match ($this) {
            self::Departed => __('Checked out today'),
            self::StayOver => __('Guest in house'),
        };
    }
}
