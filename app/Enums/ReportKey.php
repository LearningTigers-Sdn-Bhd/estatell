<?php

namespace App\Enums;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportRow;
use App\Data\Reports\ReportTotals;
use App\Services\Reports\ArrivalsReport;
use App\Services\Reports\DeparturesReport;
use App\Services\Reports\HasTotals;
use App\Services\Reports\InHouseReport;
use App\Services\Reports\OccupancyReport;
use App\Services\Reports\Report;
use App\Services\Reports\RevenueReport;

/**
 * The reports in the registry. The value is the URL slug (`/p/{code}/reports/arrivals`) and the route name suffix.
 * See docs/22-reports/01-core-reports.md.
 */
enum ReportKey: string
{
    case Arrivals = 'arrivals';
    case Departures = 'departures';
    case InHouse = 'in-house';
    case Occupancy = 'occupancy';
    case Revenue = 'revenue';

    public function label(): string
    {
        return match ($this) {
            self::Arrivals => __('Arrivals'),
            self::Departures => __('Departures'),
            self::InHouse => __('In-house'),
            self::Occupancy => __('Occupancy'),
            self::Revenue => __('Revenue'),
        };
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse => ReportCategory::FrontDesk,
            self::Occupancy, self::Revenue => ReportCategory::Revenue,
        };
    }

    public function defaultOrientation(): PageOrientation
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse, self::Occupancy, self::Revenue => PageOrientation::Portrait,
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
            self::Occupancy => 'date',
            self::Revenue => 'period',
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
            self::Occupancy, self::Revenue => 366,
        };
    }

    /**
     * The values of `?group` the report takes, the first one being the default. Empty for a report that does not group.
     *
     * @return list<ReportGroup>
     */
    public function groups(): array
    {
        return match ($this) {
            self::Revenue => [ReportGroup::Day, ReportGroup::Month],
            default => [],
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
            self::Occupancy => [
                new ReportColumn('date', __('Date'), ColumnAlign::Left, true),
                new ReportColumn('available', __('Rooms available'), ColumnAlign::Right, true),
                new ReportColumn('sold', __('Rooms sold'), ColumnAlign::Right, true),
                new ReportColumn('occupancy', __('Occupancy %'), ColumnAlign::Right, true),
                new ReportColumn('adr', __('ADR'), ColumnAlign::Right, true),
                new ReportColumn('revpar', __('RevPAR'), ColumnAlign::Right, true),
                new ReportColumn('revenue', __('Room revenue'), ColumnAlign::Right, true),
                new ReportColumn('arrivals', __('Arrivals'), ColumnAlign::Right, true),
                new ReportColumn('departures', __('Departures'), ColumnAlign::Right, true),
                new ReportColumn('noshows', __('No-shows'), ColumnAlign::Right, true),
                new ReportColumn('guests', __('In-house guests'), ColumnAlign::Right, true),
            ],
            self::Revenue => [
                new ReportColumn('period', __('Date / month'), ColumnAlign::Left, true),
                new ReportColumn('code', __('Code'), ColumnAlign::Left, true),
                new ReportColumn('description', __('Description'), ColumnAlign::Left, true),
                new ReportColumn('lines', __('Lines'), ColumnAlign::Right, true),
                new ReportColumn('amount', __('Amount'), ColumnAlign::Right, true),
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
            self::Occupancy => 'percent',
            self::Revenue => 'chart-column',
        };
    }

    public function report(): Report
    {
        return match ($this) {
            self::Arrivals => app(ArrivalsReport::class),
            self::Departures => app(DeparturesReport::class),
            self::InHouse => app(InHouseReport::class),
            self::Occupancy => app(OccupancyReport::class),
            self::Revenue => app(RevenueReport::class),
        };
    }

    /**
     * The total row of the rows given, or null when the report has none.
     *
     * @param  list<ReportRow>  $rows
     */
    public function totals(array $rows): ?ReportTotals
    {
        $report = $this->report();

        return $report instanceof HasTotals ? $report->totals($rows) : null;
    }
}
