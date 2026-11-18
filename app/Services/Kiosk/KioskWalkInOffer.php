<?php

namespace App\Services\Kiosk;

use App\Models\RatePlan;
use App\Models\RoomType;
use Brick\Money\Money;

/**
 * `$roomId` is the room a day use offer holds; a night offer leaves it null because the booking assigns the room.
 */
final readonly class KioskWalkInOffer
{
    public function __construct(public RoomType $roomType, public RatePlan $ratePlan, public Money $total, public bool $available, public ?int $roomId = null) {}
}
