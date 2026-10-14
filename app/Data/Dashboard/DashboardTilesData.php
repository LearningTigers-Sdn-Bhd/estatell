<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * The tiles of the dashboard. A tile the user may not see is null.
 */
class DashboardTilesData extends Data
{
    /**
     * @param  string  $date  The business date, `Y-m-d`.
     */
    public function __construct(
        public string $date,
        public ?UnitCountTileData $arrivals,
        public ?UnitCountTileData $departures,
        public ?TonightTileData $tonight,
        public ?MonthTileData $month,
    ) {}
}
