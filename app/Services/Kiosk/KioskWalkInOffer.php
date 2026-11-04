<?php

namespace App\Services\Kiosk;

use App\Models\RatePlan;
use App\Models\RoomType;
use Brick\Money\Money;

final readonly class KioskWalkInOffer
{
    public function __construct(public RoomType $roomType, public RatePlan $ratePlan, public Money $total, public bool $available) {}
}
