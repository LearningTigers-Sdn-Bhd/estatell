<?php

namespace App\Enums;

use App\Data\Reports\ReportColumn;
use App\Services\Reports\ArrivalsReport;
use App\Services\Reports\Report;

/**
 * The reports in the registry. The value is the URL slug (`/p/{code}/reports/arrivals`) and the route name suffix.
 * See docs/22-reports/01-core-reports.md.
 */
enum ReportKey: string
{
    case Arrivals = 'arrivals';

    public function label(): string
    {
        return match ($this) {
            self::Arrivals => __('Arrivals'),
        };
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::Arrivals => ReportCategory::FrontDesk,
        };
    }

    public function defaultOrientation(): PageOrientation
    {
        return match ($this) {
            self::Arrivals => PageOrientation::Portrait,
        };
    }

    /**
     * The longest date range, counting both ends. Null for a report of one date.
     */
    public function maxRangeDays(): ?int
    {
        return match ($this) {
            self::Arrivals => 31,
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
        };
    }

    public function report(): Report
    {
        return match ($this) {
            self::Arrivals => app(ArrivalsReport::class),
        };
    }
}
