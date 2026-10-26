<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Models\Property;

/**
 * The count on each tab of the Guest list: one COUNT query for each report, on its `query()`. Arrivals, Departures and
 * Checked out count the range, In-house counts one date.
 */
class GuestListCounts
{
    public function __construct(
        private ArrivalsReport $arrivals,
        private InHouseReport $inHouse,
        private DeparturesReport $departures,
        private CheckedOutReport $checkedOut,
    ) {}

    /**
     * @return array<string, int> The count by `?tab` value.
     */
    public function forDates(Property $property, string $from, string $to, string $date): array
    {
        $range = new ReportFilters($from, $to);
        $day = new ReportFilters($date, $date);

        return [
            'arrivals' => $this->arrivals->query($property, $range)->count(),
            'in-house' => $this->inHouse->query($property, $day)->count(),
            'departures' => $this->departures->query($property, $range)->count(),
            'checked-out' => $this->checkedOut->query($property, $range)->count(),
        ];
    }
}
