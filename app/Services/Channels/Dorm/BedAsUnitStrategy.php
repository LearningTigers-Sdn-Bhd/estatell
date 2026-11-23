<?php

namespace App\Services\Channels\Dorm;

use App\Models\RoomType;

/**
 * Each bed is one unit of an ordinary room type for one adult: `count_of_rooms` is the active beds, the rate plans
 * are sold per room at occupancy 1 with the bed price, availability is the free beds, and one room of an OTA booking
 * is one bed. Every OTA takes it. See docs/21-channels/03-dorm-strategies.md.
 */
class BedAsUnitStrategy implements DormChannelStrategy
{
    public function refusal(): ?string
    {
        return null;
    }

    public function roomKind(): string
    {
        return 'room';
    }

    public function adults(RoomType $roomType): int
    {
        return 1;
    }

    public function children(RoomType $roomType): int
    {
        return 0;
    }
}
