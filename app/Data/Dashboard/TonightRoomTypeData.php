<?php

namespace App\Data\Dashboard;

use App\Enums\SellUnit;
use Spatie\LaravelData\Data;

/**
 * One room type in the popover of the Tonight tile.
 */
class TonightRoomTypeData extends Data
{
    /**
     * @param  int  $total  Units on sale tonight: active rooms or beds that no room block takes off.
     */
    public function __construct(
        public string $name,
        public SellUnit $sellUnit,
        public int $sold,
        public int $total,
        public int $left,
    ) {}
}
