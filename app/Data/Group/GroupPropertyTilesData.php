<?php

namespace App\Data\Group;

use App\Data\Dashboard\DashboardTilesData;
use App\Data\Property\PropertySummaryData;
use Spatie\LaravelData\Data;

/**
 * One property on the group dashboard: who it is, its own business date, and its dashboard tiles as the user's role
 * there allows (null tiles are hidden, as on the property dashboard). See docs/03-staff/05-group-mode.md.
 */
class GroupPropertyTilesData extends Data
{
    public function __construct(
        public PropertySummaryData $property,
        public ?string $businessDate,
        public ?DashboardTilesData $tiles,
    ) {}
}
