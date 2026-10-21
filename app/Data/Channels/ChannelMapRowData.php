<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One line of the map page: an OTA room, rate and guest count, and the estatell plan it maps to. `occupancy` is null when
 * the OTA lists no guest counts for the rate (per-room pricing). `estatell` is the `value` of one
 * `ChannelEstatellOptionData` (a rate plan mapping id), or null while unmapped.
 */
class ChannelMapRowData extends Data
{
    public function __construct(
        public string $key,
        public string $roomCode,
        public string $roomTitle,
        public string $rateCode,
        public string $rateTitle,
        public ?int $occupancy,
        public ?string $estatell,
        public bool $isPrimary,
        public ?string $rateType,
    ) {}
}
