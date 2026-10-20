<?php

namespace App\Enums;

/**
 * How a rate plan is sold on the channel manager. The values are the ones Channex takes for `sell_mode`. This is the
 * channel side only: estatell prices a plan by its own pricing basis. A per-room plan whose price depends on the
 * number of adults travels as PerPerson, because Channex PerRoom holds one price for any number of guests.
 */
enum ChannelSellMode: string
{
    case PerRoom = 'per_room';
    case PerPerson = 'per_person';

    public function label(): string
    {
        return match ($this) {
            self::PerRoom => __('One price for the room'),
            self::PerPerson => __('A price for each adult count'),
        };
    }
}
