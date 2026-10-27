<?php

namespace App\Enums;

/**
 * Why a room block was not created or saved. The Actions return it instead of throwing.
 */
enum RoomBlockRefusal: string
{
    case Unavailable = 'unavailable';
    case Ended = 'ended';
    case Blocked = 'blocked';
    case HasStay = 'has_stay';

    /**
     * @param  string  $unit  "Room 203" or "Room 12, bed A3".
     */
    public function message(string $unit): string
    {
        return match ($this) {
            self::Unavailable => __(':unit is no longer active.', ['unit' => $unit]),
            self::Ended => __('Block for :unit was not saved. It has ended.', ['unit' => $unit]),
            self::Blocked => __(':unit is already blocked for some of these nights.', ['unit' => $unit]),
            self::HasStay => __(':unit has a stay on some of these nights. Move the stay first.', ['unit' => $unit]),
        };
    }
}
