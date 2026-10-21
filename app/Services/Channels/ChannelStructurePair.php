<?php

namespace App\Services\Channels;

use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * One room type and rate plan pair with its capability answer.
 */
final readonly class ChannelStructurePair
{
    public function __construct(
        public RoomType $roomType,
        public RatePlan $ratePlan,
        public ChannelCapabilityResult $result,
    ) {}
}
