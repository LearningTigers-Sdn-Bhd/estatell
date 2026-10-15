<?php

namespace App\Services\Housekeeping;

use App\Data\Dashboard\HousekeepingTileData;
use App\Enums\HousekeepingStatus;
use App\Models\Property;
use Carbon\CarbonImmutable;

class HousekeepingSummary
{
    public function __construct(private HousekeepingBoardBuilder $board) {}

    public function forDate(Property $property, CarbonImmutable $date): HousekeepingTileData
    {
        $rows = collect($this->board->rows($property, $date, false, false, false));

        return new HousekeepingTileData(
            dirty: $rows->where('status.value', HousekeepingStatus::Dirty->value)->count(),
            toInspect: $rows->where('status.value', HousekeepingStatus::Clean->value)->count(),
            blocked: $rows->where('status.value', 'out_of_service')->count(),
        );
    }
}
