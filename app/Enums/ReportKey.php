<?php

namespace App\Enums;

use App\Data\Reports\ReportBreakdown;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;
use App\Data\Reports\ReportTotals;
use App\Services\Reports\ArrivalsReport;
use App\Services\Reports\DeparturesReport;
use App\Services\Reports\FolioLedgerReport;
use App\Services\Reports\HasBreakdown;
use App\Services\Reports\HasTotals;
use App\Services\Reports\InHouseReport;
use App\Services\Reports\MealCountsReport;
use App\Services\Reports\NightAuditHistoryReport;
use App\Services\Reports\OccupancyReport;
use App\Services\Reports\PaymentsReport;
use App\Services\Reports\Report;
use App\Services\Reports\RevenueReport;
use App\Services\Reports\TaxReport;

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
    case Payments = 'payments';
    case FolioLedger = 'folio-ledger';
    case Tax = 'tax';
    case MealCounts = 'meal-counts';
    case NightAuditHistory = 'night-audit-history';

    public function label(): string
    {
        return match ($this) {
            self::Arrivals => __('Arrivals'),
            self::Departures => __('Departures'),
            self::InHouse => __('In-house'),
            self::Occupancy => __('Occupancy'),
            self::Revenue => __('Revenue'),
            self::Payments => __('Payments'),
            self::FolioLedger => __('Folio ledger'),
            self::Tax => __('Tax'),
            self::MealCounts => __('Meal counts'),
            self::NightAuditHistory => __('Night audit history'),
        };
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse => ReportCategory::FrontDesk,
            self::Occupancy, self::Revenue, self::Tax => ReportCategory::Revenue,
            self::Payments, self::FolioLedger => ReportCategory::Finance,
            self::MealCounts => ReportCategory::Kitchen,
            self::NightAuditHistory => ReportCategory::Audit,
        };
    }

    public function defaultOrientation(): PageOrientation
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse, self::Occupancy, self::Revenue, self::Payments, self::MealCounts => PageOrientation::Portrait,
            self::FolioLedger, self::Tax, self::NightAuditHistory => PageOrientation::Landscape,
        };
    }

    /**
     * The sort the page and the exports use when none is given or the sort column is hidden: a column key, with a
     * leading `-` for descending.
     */
    public function defaultSort(?ReportGroup $group = null): string
    {
        return match ($this) {
            self::Payments => $group === ReportGroup::Staff ? 'staff' : 'date',
            self::Arrivals => 'arrival',
            self::Departures => 'departure',
            self::InHouse => 'room',
            self::Occupancy => 'date',
            self::Revenue, self::Tax => 'period',
            self::FolioLedger, self::MealCounts => 'date',
            self::NightAuditHistory => '-date',
        };
    }

    /**
     * The longest date range, counting both ends. Null for a report of one date.
     */
    public function maxRangeDays(): ?int
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::FolioLedger, self::MealCounts => 31,
            self::InHouse => null,
            self::Occupancy, self::Revenue, self::Payments, self::Tax, self::NightAuditHistory => 366,
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
            self::Revenue, self::Tax => [ReportGroup::Day, ReportGroup::Month],
            self::Payments => [ReportGroup::Method, ReportGroup::Staff],
            default => [],
        };
    }

    /**
     * The quick dates of a range report, in the order shown, or null for the standard list (Today, Yesterday, This
     * week, Last week, This month, Last month, This year, Last 30 days). A preset longer than the longest range is
     * left out by the date picker.
     *
     * @return list<ReportDatePreset>|null
     */
    public function datePresets(): ?array
    {
        return match ($this) {
            self::MealCounts => [ReportDatePreset::Today, ReportDatePreset::Tomorrow, ReportDatePreset::Next7Days, ReportDatePreset::ThisWeek, ReportDatePreset::NextWeek],
            default => null,
        };
    }

    /**
     * The number of days a range report opens on when the URL has no dates, counted back from the business date and
     * including it. 1 is Today. The page sends the resulting `from` and `to`, so the quick dates list needs no change.
     */
    public function defaultDays(): int
    {
        return match ($this) {
            self::NightAuditHistory => 30,
            default => 1,
        };
    }

    /**
     * Whether the report takes `?code`, a transaction code to list the lines of. Other reports ignore it.
     */
    public function hasCodeFilter(): bool
    {
        return $this === self::FolioLedger;
    }

    /**
     * Whether the report takes `?staff` (a user of the property) and `?refunds` (refunds only). Other reports ignore them.
     */
    public function hasPaymentFilters(): bool
    {
        return $this === self::Payments;
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
            self::Payments => [
                new ReportColumn('date', __('Date'), ColumnAlign::Left, true),
                new ReportColumn('receipt', __('Receipt no.'), ColumnAlign::Left, true),
                new ReportColumn('payer', __('Reservation / company'), ColumnAlign::Left, true),
                new ReportColumn('method', __('Method'), ColumnAlign::Left, true),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('staff', __('Staff'), ColumnAlign::Left, true),
                new ReportColumn('amount', __('Amount'), ColumnAlign::Right, true),
                new ReportColumn('reference', __('Reference'), ColumnAlign::Left, false),
                new ReportColumn('refund_of', __('Original receipt'), ColumnAlign::Left, false),
            ],
            self::FolioLedger => [
                new ReportColumn('date', __('Posting date'), ColumnAlign::Left, true),
                new ReportColumn('folio', __('Folio'), ColumnAlign::Left, true),
                new ReportColumn('reservation', __('Reservation'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest / payer'), ColumnAlign::Left, true),
                new ReportColumn('code', __('Code'), ColumnAlign::Left, true),
                new ReportColumn('description', __('Description'), ColumnAlign::Left, true),
                new ReportColumn('kind', __('Kind'), ColumnAlign::Left, true),
                new ReportColumn('staff', __('Staff'), ColumnAlign::Left, true),
                new ReportColumn('amount', __('Amount'), ColumnAlign::Right, true),
            ],
            self::Tax => [
                new ReportColumn('period', __('Date / month'), ColumnAlign::Left, true),
                new ReportColumn('tax', __('Tax'), ColumnAlign::Left, true),
                new ReportColumn('registration', __('Registration no.'), ColumnAlign::Left, false),
                new ReportColumn('lines', __('Lines'), ColumnAlign::Right, true),
                new ReportColumn('amount', __('Tax amount'), ColumnAlign::Right, true),
            ],
            self::MealCounts => [
                new ReportColumn('date', __('Date'), ColumnAlign::Left, true),
                new ReportColumn('meal', __('Meal'), ColumnAlign::Left, true),
                new ReportColumn('adults', __('Adults'), ColumnAlign::Right, true),
                new ReportColumn('children', __('Children'), ColumnAlign::Right, true),
                new ReportColumn('total', __('Total'), ColumnAlign::Right, true),
            ],
            self::NightAuditHistory => [
                new ReportColumn('date', __('Date'), ColumnAlign::Left, true),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('trigger', __('Trigger'), ColumnAlign::Left, true),
                new ReportColumn('run_by', __('Run by'), ColumnAlign::Left, true),
                new ReportColumn('started', __('Started'), ColumnAlign::Left, true),
                new ReportColumn('completed', __('Completed'), ColumnAlign::Left, true),
                new ReportColumn('forced', __('Forced'), ColumnAlign::Left, true),
                new ReportColumn('exceptions', __('Exceptions (open / total)'), ColumnAlign::Right, true),
                new ReportColumn('occupancy', __('Occupancy %'), ColumnAlign::Right, true),
                new ReportColumn('revenue', __('Room revenue'), ColumnAlign::Right, true),
                new ReportColumn('packet', __('Packet'), ColumnAlign::Left, true),
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
            self::Payments => 'credit-card',
            self::FolioLedger => 'receipt',
            self::Tax => 'landmark',
            self::MealCounts => 'utensils',
            self::NightAuditHistory => 'moon',
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
            self::Payments => app(PaymentsReport::class),
            self::FolioLedger => app(FolioLedgerReport::class),
            self::Tax => app(TaxReport::class),
            self::MealCounts => app(MealCountsReport::class),
            self::NightAuditHistory => app(NightAuditHistoryReport::class),
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

    /**
     * The summary table under the report, or null when the report has none.
     *
     * @param  list<ReportRow>  $rows
     */
    public function breakdown(array $rows, ReportFilters $filters): ?ReportBreakdown
    {
        $report = $this->report();

        return $report instanceof HasBreakdown ? $report->breakdown($rows, $filters) : null;
    }
}
