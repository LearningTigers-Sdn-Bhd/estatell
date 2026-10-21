<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One OTA room and rate to choose from on the map page. `value` is `{roomCode}|{rateCode}`.
 */
class ChannelOtaOptionData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
