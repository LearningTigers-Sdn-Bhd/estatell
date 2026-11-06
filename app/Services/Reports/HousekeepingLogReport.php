<?php

namespace App\Services\Reports;

use App\Data\Reports\HousekeepingLogRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\Property;
use App\Models\RoomCleaning;

/**
 * Who cleaned which room and when, between two dates: one row per cleaning. Rooms and staff archived later keep their
 * rows. See docs/19-housekeeping/03-cleaning-times.md#housekeeping-log.
 */
class HousekeepingLogReport implements HasTotals, Report
{
    /**
     * @param  list<int|string>|null  $rowKeys  Cleaning ids.
     * @return list<HousekeepingLogRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');

        return array_values(RoomCleaning::query()
            ->where('property_id', $property->id)
            ->whereDate('date', '>=', $filters->from)
            ->whereDate('date', '<=', $filters->to)
            ->when($rowKeys !== null, fn ($query) => $query->whereIn('id', array_map(intval(...), $rowKeys)))
            ->with(['housekeeper:id,name', 'room.roomType:id,name'])
            ->orderBy('date')
            ->orderBy('started_at')
            ->get()
            ->map(fn (RoomCleaning $cleaning): HousekeepingLogRow => new HousekeepingLogRow(
                key: $cleaning->id,
                date: $cleaning->date->toDateString(),
                room: $cleaning->room->number,
                room_type: $cleaning->room->roomType->name,
                housekeeper: $cleaning->housekeeper->name,
                in: $cleaning->started_at->setTimezone($timeZone)->format('H:i'),
                out: $cleaning->finished_at?->setTimezone($timeZone)->format('H:i'),
                minutes: $cleaning->finished_at === null ? null : (int) $cleaning->started_at->diffInMinutes($cleaning->finished_at),
            ))
            ->all());
    }

    /**
     * The minutes of the finished cleanings.
     *
     * @param  list<HousekeepingLogRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'date' => null,
            'room' => null,
            'room_type' => null,
            'housekeeper' => null,
            'in' => null,
            'out' => null,
            'minutes' => array_sum(array_map(fn (HousekeepingLogRow $row): int => $row->minutes ?? 0, $rows)),
        ]);
    }
}
