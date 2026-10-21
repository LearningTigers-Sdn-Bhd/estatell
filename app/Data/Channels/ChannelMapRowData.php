<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One line of the map page: an occupancy of one of our Channex rate plans, and the OTA room and rate it maps to. `ota` is
 * `{roomCode}|{rateCode}` of one `ChannelOtaOptionData`, or null while unmapped.
 */
class ChannelMapRowData extends Data
{
    public function __construct(
        public string $key,
        public int $ratePlanMappingId,
        public string $roomType,
        public string $ratePlan,
        public int $occupancy,
        public ?string $ota,
        public bool $isPrimary,
        public ?string $rateType,
    ) {}
}
