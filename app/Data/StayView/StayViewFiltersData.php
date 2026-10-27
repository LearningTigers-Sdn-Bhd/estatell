<?php

namespace App\Data\StayView;

use App\Enums\StayViewGrouping;
use Spatie\LaravelData\Data;

/**
 * The filters and Group by of the Stay View, as the URL carries them. Nothing selected in a list means all.
 */
class StayViewFiltersData extends Data
{
    /**
     * @param  list<int>  $roomGroupIds
     * @param  list<int>  $roomTypeIds
     * @param  list<string>  $statuses  Housekeeping statuses.
     * @param  list<string>  $show  Room View only: the card states to show. Nothing selected means all.
     */
    public function __construct(
        public array $roomGroupIds,
        public array $roomTypeIds,
        public array $statuses,
        public string $search,
        public StayViewGrouping $groupBy,
        public array $show = [],
    ) {}
}
