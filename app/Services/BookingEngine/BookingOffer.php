<?php

namespace App\Services\BookingEngine;

use App\Models\RatePlan;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use Brick\Money\Money;

/**
 * One room type on one public rate plan for a stay: one room or one bed, with what is left. `price` is null when
 * `blockedCode` is set. `blockedCode` is stable; `blockedMessage` is safe to show. `roomTotal` is the stay price before tax.
 */
class BookingOffer
{
    public function __construct(
        public readonly RoomType $roomType,
        public readonly RatePlan $ratePlan,
        public readonly int $roomsLeft,
        public readonly ?OnlinePrice $price,
        public readonly ?RoomRevenuePolicy $cancellationPolicy,
        public readonly ?string $blockedCode = null,
        public readonly ?string $blockedMessage = null,
        public readonly ?Money $roomTotal = null,
    ) {}
}
