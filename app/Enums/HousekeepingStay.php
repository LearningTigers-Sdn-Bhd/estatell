<?php

namespace App\Enums;

/**
 * Where the guest of a room stands today, for housekeeping. It is worked out from the room's units each time the board
 * loads, never stored. Tones follow `UnitStatus`. See docs/19-housekeeping/01-room-status-board.md.
 */
enum HousekeepingStay: string
{
    case DueOut = 'due_out';
    case InHouse = 'in_house';
    case CheckedOut = 'checked_out';
    case Arriving = 'arriving';

    public function label(): string
    {
        return match ($this) {
            self::DueOut => __('Due out'),
            self::InHouse => __('In house'),
            self::CheckedOut => __('Checked out'),
            self::Arriving => __('Arriving'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::DueOut => Tone::Warning,
            self::InHouse => Tone::Success,
            self::CheckedOut => Tone::Neutral,
            self::Arriving => Tone::Info,
        };
    }
}
