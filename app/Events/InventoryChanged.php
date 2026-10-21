<?php

namespace App\Events;

use App\Models\RoomBlock;
use App\Services\Channels\ChannelAriWindow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The rooms a room type can sell changed for some dates: a room block, or a room or bed added, archived or moved to another room type.
 * The dates are the nights that changed, `Y-m-d`, both included.
 */
class InventoryChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $propertyId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $roomTypeId = null,
        public readonly ?int $ratePlanId = null,
    ) {}

    /**
     * The units of a room type changed, so every day of the next 500 is affected.
     */
    public static function forRoomType(int $propertyId, int $roomTypeId): self
    {
        return new self($propertyId, now()->toDateString(), now()->addDays(ChannelAriWindow::DAYS)->toDateString(), $roomTypeId);
    }

    /**
     * The nights a room block takes off sale, or gives back.
     */
    public static function forBlock(RoomBlock $block): self
    {
        $block->loadMissing('room:id,room_type_id');

        return new self($block->property_id, $block->starts_on->toDateString(), $block->ends_on->toDateString(), $block->room->room_type_id);
    }
}
