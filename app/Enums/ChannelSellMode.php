<?php

namespace App\Enums;

use App\Models\RoomType;

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

    /**
     * The occupancy options Channex holds for a plan of this mode: one at the top adult count per room, or one for each
     * adult count from 1 to the top per person.
     *
     * @return list<int>
     */
    public function occupancies(RoomType $roomType): array
    {
        return $this === self::PerPerson ? range(1, $roomType->max_adults) : [$roomType->max_adults];
    }
}
