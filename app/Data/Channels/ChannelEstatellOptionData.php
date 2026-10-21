<?php

namespace App\Data\Channels;

use Spatie\LaravelData\Data;

/**
 * One Estatell rate plan an OTA line can map to. `value` is the rate plan mapping id. A plan sold per room has one option
 * at Channex, so any OTA guest count can map to it (`perRoom`). A plan sold per person has one option for each of its
 * `occupancies`, and an OTA line maps to the option with its own guest count.
 */
class ChannelEstatellOptionData extends Data
{
    /**
     * @param  list<int>  $occupancies
     */
    public function __construct(
        public string $value,
        public int $roomTypeId,
        public string $roomType,
        public string $ratePlan,
        public bool $perRoom,
        public array $occupancies,
    ) {}
}
