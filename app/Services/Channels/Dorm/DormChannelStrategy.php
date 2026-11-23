<?php

namespace App\Services\Channels\Dorm;

use App\Models\RoomType;

/**
 * How a dorm room type travels to the channel manager: whether it can go, the room kind, and the guests one unit
 * holds there. See docs/21-channels/03-dorm-strategies.md.
 */
interface DormChannelStrategy
{
    /**
     * Why the dorm cannot go to channels with this strategy, or null when it can.
     */
    public function refusal(): ?string;

    /**
     * The channel manager's room kind.
     */
    public function roomKind(): string;

    /**
     * The adults one channel unit holds: the occupancy of the room type and of its rate plans on the channel.
     */
    public function adults(RoomType $roomType): int;

    /**
     * The children one channel unit holds.
     */
    public function children(RoomType $roomType): int;
}
