<?php

namespace App\Enums;

use App\Data\Reports\ReportColumn;
use App\Services\Reports\ArrivalsReport;
use App\Services\Reports\DeparturesReport;
use App\Services\Reports\InHouseReport;
use App\Services\Reports\Report;

/**
 * The reports in the registry. The value is the URL slug (`/p/{code}/reports/arrivals`) and the route name suffix.
 * See docs/22-reports/01-core-reports.md.
 */
enum ReportKey: string
{
    case Arrivals = 'arrivals';
    case Departures = 'departures';
    case InHouse = 'in-house';

    public function label(): string
    {
        return match ($this) {
            self::Arrivals => __('Arrivals'),
            self::Departures => __('Departures'),
            self::InHouse => __('In-house'),
        };
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse => ReportCategory::FrontDesk,
        };
    }

    public function defaultOrientation(): PageOrientation
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse => PageOrientation::Portrait,
        };
    }

    /**
     * The sort the page and the exports use when none is given or the sort column is hidden: a column key, with a
     * leading `-` for descending.
     */
    public function defaultSort(): string
    {
        return match ($this) {
            self::Arrivals => 'arrival',
            self::Departures => 'departure',
            self::InHouse => 'room',
        };
    }

    /**
     * The longest date range, counting both ends. Null for a report of one date.
     */
    public function maxRangeDays(): ?int
    {
        return match ($this) {
            self::Arrivals, self::Departures => 31,
            self::InHouse => null,
        };
    }

    /**
     * The columns in their default order.
     *
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Arrivals => [
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('phone', __('Phone'), ColumnAlign::Left, false),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('nights', __('Nights'), ColumnAlign::Right, true),
                new ReportColumn('unit', __('Room type / room'), ColumnAlign::Left, true),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, false),
                new ReportColumn('company', __('Company'), ColumnAlign::Left, false),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('balance', __('Balance'), ColumnAlign::Right, true),
            ],
            self::Departures => [
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('phone', __('Phone'), ColumnAlign::Left, false),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('nights', __('Nights'), ColumnAlign::Right, true),
                new ReportColumn('unit', __('Room type / room'), ColumnAlign::Left, true),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, false),
                new ReportColumn('company', __('Company'), ColumnAlign::Left, false),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('balance', __('Balance'), ColumnAlign::Right, true),
            ],
            self::InHouse => [
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('room', __('Room'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, true),
                new ReportColumn('company', __('Company'), ColumnAlign::Left, false),
                new ReportColumn('balance', __('Balance'), ColumnAlign::Right, true),
            ],
        };
    }

    /**
     * The sidebar icon name, one of the names in `resources/js/lib/navigation.ts`.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Arrivals => 'calendar-check',
            self::Departures => 'door-open',
            self::InHouse => 'users',
        };
    }

    public function report(): Report
    {
        return match ($this) {
            self::Arrivals => app(ArrivalsReport::class),
            self::Departures => app(DeparturesReport::class),
            self::InHouse => app(InHouseReport::class),
        };
    }
}
