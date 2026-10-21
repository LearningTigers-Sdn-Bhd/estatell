<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One estatell rate plan occupancy an OTA line can map to. `value` is `{rate plan mapping id}-{occupancy}`, and the room type and plan names are shown apart so the page can choose the room first. `isTop` marks
 * the highest occupancy of its plan, which is the one an OTA line with no guest count takes.
 */
class ChannelEstatellOptionData extends Data
{
    public function __construct(
        public string $value,
        public int $roomTypeId,
        public string $roomType,
        public string $ratePlan,
        public int $occupancy,
        public bool $isTop,
    ) {}
}
