<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One estatell rate plan occupancy an OTA line can map to. `value` is `{rate plan mapping id}-{occupancy}`. `isTop` marks
 * the highest occupancy of its plan, which is the one an OTA line with no guest count takes.
 */
class ChannelEstatellOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public int $occupancy,
        public bool $isTop,
    ) {}
}
