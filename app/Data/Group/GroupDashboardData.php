<?php

namespace App\Data\Group;

use Spatie\LaravelData\Data;

/**
 * The group dashboard: the totals, then one block for each property the user may open in the group.
 */
class GroupDashboardData extends Data
{
    /**
     * @param  list<GroupPropertyTilesData>  $properties
     */
    public function __construct(
        public GroupTotalsData $totals,
        public array $properties,
    ) {}
}
