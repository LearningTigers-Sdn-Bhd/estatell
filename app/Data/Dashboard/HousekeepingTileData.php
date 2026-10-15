<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

class HousekeepingTileData extends Data
{
    public function __construct(public int $dirty, public int $toInspect, public int $blocked) {}
}
