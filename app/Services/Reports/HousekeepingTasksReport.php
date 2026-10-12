<?php

namespace App\Services\Reports;

use App\Data\Reports\HousekeepingTaskRow;
use App\Data\Reports\ReportFilters;
use App\Models\Property;
use App\Services\Housekeeping\HousekeepingBoardBuilder;
use Carbon\CarbonImmutable;

/**
 * Every active room of the property for one date, in the board order. It reads the board builder, so the screen, the
 * PDF and the spreadsheet show the same rooms. See docs/19-housekeeping/02-print-and-layout.md.
 */
class HousekeepingTasksReport implements Report
{
    public function __construct(private HousekeepingBoardBuilder $builder) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Room ids. An id that is not an active room of the property is dropped.
     * @return list<HousekeepingTaskRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $wanted = $rowKeys === null ? null : array_flip(array_map('intval', $rowKeys));
        $rows = [];

        foreach ($this->builder->rows($property, CarbonImmutable::parse($filters->from), false, false, false) as $position => $room) {
            if ($wanted === null || isset($wanted[$room->id])) {
                $rows[] = HousekeepingTaskRow::fromRoom($room, $position + 1);
            }
        }

        return $rows;
    }
}
