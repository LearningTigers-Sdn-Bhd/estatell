<?php

namespace App\Enums;

use App\Data\Reports\ReportBreakdown;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;
use App\Data\Reports\ReportSection;
use App\Data\Reports\ReportTotals;
use App\Features\Boats;
use App\Models\Property;
use App\Services\Reports\ArrivalsReport;
use App\Services\Reports\BoatManifestReport;
use App\Services\Reports\DeparturesReport;
use App\Services\Reports\DepositLiabilityReport;
use App\Services\Reports\FolioLedgerReport;
use App\Services\Reports\HasBreakdown;
use App\Services\Reports\HasNote;
use App\Services\Reports\HasSections;
use App\Services\Reports\HasTotals;
use App\Services\Reports\HousekeepingTasksReport;
use App\Services\Reports\InHouseReport;
use App\Services\Reports\MealCountsReport;
use App\Services\Reports\NightAuditHistoryReport;
use App\Services\Reports\NonNationalGuestsReport;
use App\Services\Reports\OccupancyReport;
use App\Services\Reports\OutstandingBalanceReport;
use App\Services\Reports\PaymentsReport;
use App\Services\Reports\PoliceReport;
use App\Services\Reports\Report;
use App\Services\Reports\RevenueReport;
use App\Services\Reports\TaxReport;
use Laravel\Pennant\Feature;

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
    case OutstandingBalance = 'outstanding-balance';
    case DepositLiability = 'deposit-liability';
    case NonNationalGuests = 'non-national-guests';
    case PoliceReport = 'police-report';
    case HousekeepingTasks = 'housekeeping-tasks';
    case BoatManifest = 'boat-manifest';

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
            self::OutstandingBalance => __('Outstanding balance'),
            self::DepositLiability => __('Deposit liability'),
            self::NonNationalGuests => __('Non-national guests'),
            self::PoliceReport => __('Police report'),
            self::HousekeepingTasks => __('Housekeeping tasks'),
            self::BoatManifest => __('Boat manifest'),
        };
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse, self::PoliceReport, self::HousekeepingTasks, self::BoatManifest => ReportCategory::FrontDesk,
            self::Occupancy, self::Revenue, self::Tax, self::NonNationalGuests => ReportCategory::Revenue,
            self::Payments, self::FolioLedger, self::OutstandingBalance, self::DepositLiability => ReportCategory::Finance,
            self::MealCounts => ReportCategory::Kitchen,
            self::NightAuditHistory => ReportCategory::Audit,
        };
    }

    public function defaultOrientation(): PageOrientation
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::InHouse, self::Occupancy, self::Revenue, self::Payments, self::MealCounts, self::BoatManifest => PageOrientation::Portrait,
            self::FolioLedger, self::Tax, self::NightAuditHistory, self::OutstandingBalance, self::DepositLiability, self::NonNationalGuests, self::PoliceReport, self::HousekeepingTasks => PageOrientation::Landscape,
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
            self::OutstandingBalance => '-days',
            self::DepositLiability, self::NonNationalGuests => 'arrival',
            self::PoliceReport => 'room',
            self::HousekeepingTasks => 'due',
            self::BoatManifest => 'departure',
        };
    }

    /**
     * The longest date range, counting both ends. Null for a report of one date.
     */
    public function maxRangeDays(): ?int
    {
        return match ($this) {
            self::Arrivals, self::Departures, self::FolioLedger, self::MealCounts, self::NonNationalGuests, self::PoliceReport => 31,
            self::InHouse, self::OutstandingBalance, self::DepositLiability, self::HousekeepingTasks, self::BoatManifest => null,
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
            self::BoatManifest => [ReportDatePreset::Today, ReportDatePreset::Tomorrow],
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
     * Whether the report takes `?foreign` (foreign guests only). Other reports ignore it.
     */
    public function hasForeignFilter(): bool
    {
        return $this === self::PoliceReport;
    }

    /**
     * Whether the report takes `?direction` (Boat in or Boat out). Other reports ignore it.
     */
    public function hasDirectionFilter(): bool
    {
        return $this === self::BoatManifest;
    }

    /**
     * Whether the rows keep the order `rows()` gives, because sections are read in that order. The page and the
     * exports do not sort such a report.
     */
    public function hasFixedOrder(): bool
    {
        return $this === self::BoatManifest;
    }

    /**
     * The Pennant feature the report needs, or null when it is always on. While the feature is off the report is not in
     * the sidebar and its routes return 404.
     *
     * @return class-string|null
     */
    public function feature(): ?string
    {
        return match ($this) {
            self::BoatManifest => Boats::class,
            default => null,
        };
    }

    /**
     * Whether the report is on for the property: it needs no feature, or its feature is active for the property.
     */
    public function isAvailable(Property $property): bool
    {
        $feature = $this->feature();

        return $feature === null || Feature::for($property)->active($feature);
    }

    /**
     * Whether the report shows ID numbers. The number is masked unless the user has `guests.view-documents`, and an
     * export that holds full numbers is written to the activity log.
     */
    public function showsIdentityNumbers(): bool
    {
        return in_array($this, [self::NonNationalGuests, self::PoliceReport], true);
    }

    /**
     * The columns of the report that are on for the property: `columns()` without the ones whose feature is off.
     *
     * @return list<ReportColumn>
     */
    public function columnsFor(Property $property): array
    {
        return array_values(array_filter(
            $this->columns(),
            fn (ReportColumn $column): bool => $column->feature === null || Feature::for($property)->active($column->feature),
        ));
    }

    /**
     * The columns in their default order.
     *
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return match ($this) {
            self::BoatManifest => [
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('unit', __('Room'), ColumnAlign::Left, true),
                new ReportColumn('lead_guest', __('Lead guest'), ColumnAlign::Left, true),
                new ReportColumn('phone', __('Phone'), ColumnAlign::Left, true),
                new ReportColumn('adults', __('Adults'), ColumnAlign::Right, true),
                new ReportColumn('children', __('Children'), ColumnAlign::Right, true),
                new ReportColumn('total', __('Total'), ColumnAlign::Right, true),
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, false),
                new ReportColumn('note', __('Note'), ColumnAlign::Left, false),
            ],
            self::Arrivals => [
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('phone', __('Phone'), ColumnAlign::Left, false),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('arrival_time', __('Arrival time'), ColumnAlign::Left, true),
                new ReportColumn('boat', __('Boat'), ColumnAlign::Left, true, Boats::class),
                new ReportColumn('nights', __('Nights'), ColumnAlign::Right, true),
                new ReportColumn('unit', __('Room type / room'), ColumnAlign::Left, true),
                new ReportColumn('room_type', __('Room type'), ColumnAlign::Left, false),
                new ReportColumn('room', __('Room'), ColumnAlign::Left, false),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, false),
                new ReportColumn('adults', __('Adults'), ColumnAlign::Right, false),
                new ReportColumn('children', __('Children'), ColumnAlign::Right, false),
                new ReportColumn('company', __('Company'), ColumnAlign::Left, false),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('balance', __('Balance'), ColumnAlign::Right, true),
            ],
            self::Departures => [
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('phone', __('Phone'), ColumnAlign::Left, false),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('departure_time', __('Departure time'), ColumnAlign::Left, true),
                new ReportColumn('boat', __('Boat'), ColumnAlign::Left, true, Boats::class),
                new ReportColumn('nights', __('Nights'), ColumnAlign::Right, true),
                new ReportColumn('unit', __('Room type / room'), ColumnAlign::Left, true),
                new ReportColumn('room_type', __('Room type'), ColumnAlign::Left, false),
                new ReportColumn('room', __('Room'), ColumnAlign::Left, false),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, false),
                new ReportColumn('adults', __('Adults'), ColumnAlign::Right, false),
                new ReportColumn('children', __('Children'), ColumnAlign::Right, false),
                new ReportColumn('company', __('Company'), ColumnAlign::Left, false),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('balance', __('Balance'), ColumnAlign::Right, true),
            ],
            self::InHouse => [
                new ReportColumn('reservation', __('Reservation no.'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('room', __('Room'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('arrival_time', __('Arrival time'), ColumnAlign::Left, false),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('departure_time', __('Departure time'), ColumnAlign::Left, false),
                new ReportColumn('stay', __('Stay'), ColumnAlign::Left, true),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, true),
                new ReportColumn('adults', __('Adults'), ColumnAlign::Right, false),
                new ReportColumn('children', __('Children'), ColumnAlign::Right, false),
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
                new ReportColumn('day_use_sold', __('Day use sold'), ColumnAlign::Right, true),
                new ReportColumn('day_use_revenue', __('Day use revenue'), ColumnAlign::Right, true),
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
                new ReportColumn('started_date', __('Started date'), ColumnAlign::Left, false),
                new ReportColumn('started_time', __('Started time'), ColumnAlign::Left, false),
                new ReportColumn('completed', __('Completed'), ColumnAlign::Left, true),
                new ReportColumn('completed_date', __('Completed date'), ColumnAlign::Left, false),
                new ReportColumn('completed_time', __('Completed time'), ColumnAlign::Left, false),
                new ReportColumn('forced', __('Forced'), ColumnAlign::Left, true),
                new ReportColumn('exceptions', __('Exceptions (open / total)'), ColumnAlign::Right, true),
                new ReportColumn('exceptions_open', __('Open exceptions'), ColumnAlign::Right, false),
                new ReportColumn('exceptions_total', __('Exceptions'), ColumnAlign::Right, false),
                new ReportColumn('occupancy', __('Occupancy %'), ColumnAlign::Right, true),
                new ReportColumn('revenue', __('Room revenue'), ColumnAlign::Right, true),
                new ReportColumn('packet', __('Packet'), ColumnAlign::Left, true),
            ],
            self::OutstandingBalance => [
                new ReportColumn('folio', __('Folio'), ColumnAlign::Left, true),
                new ReportColumn('reservation', __('Reservation'), ColumnAlign::Left, true),
                new ReportColumn('payer', __('Guest / payer'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('charges', __('Charges'), ColumnAlign::Right, true),
                new ReportColumn('payments', __('Payments'), ColumnAlign::Right, true),
                new ReportColumn('balance', __('Balance'), ColumnAlign::Right, true),
                new ReportColumn('days', __('Days since departure'), ColumnAlign::Right, true),
            ],
            self::DepositLiability => [
                new ReportColumn('folio', __('Folio'), ColumnAlign::Left, true),
                new ReportColumn('reservation', __('Reservation'), ColumnAlign::Left, true),
                new ReportColumn('payer', __('Guest / payer'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('received', __('Received'), ColumnAlign::Right, true),
                new ReportColumn('refunded', __('Refunded'), ColumnAlign::Right, true),
                new ReportColumn('charged', __('Charged'), ColumnAlign::Right, true),
                new ReportColumn('deposit', __('Security deposit'), ColumnAlign::Right, true),
                new ReportColumn('held', __('Held'), ColumnAlign::Right, true),
            ],
            self::NonNationalGuests => [
                new ReportColumn('reservation', __('Reservation'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('nationality', __('Nationality'), ColumnAlign::Left, true),
                new ReportColumn('identity', __('ID'), ColumnAlign::Left, true),
                new ReportColumn('id_type', __('ID type'), ColumnAlign::Left, false),
                new ReportColumn('id_number', __('ID number'), ColumnAlign::Left, false),
                new ReportColumn('room', __('Room'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('arrival_time', __('Arrival time'), ColumnAlign::Left, false),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('departure_time', __('Departure time'), ColumnAlign::Left, false),
                new ReportColumn('nights', __('Nights'), ColumnAlign::Right, true),
                new ReportColumn('guests', __('Adults / children'), ColumnAlign::Right, true),
                new ReportColumn('adults', __('Adults'), ColumnAlign::Right, false),
                new ReportColumn('children', __('Children'), ColumnAlign::Right, false),
                new ReportColumn('tourism_tax', __('Tourism tax'), ColumnAlign::Right, true),
            ],
            self::PoliceReport => [
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('reservation', __('Reservation'), ColumnAlign::Left, true),
                new ReportColumn('room', __('Room'), ColumnAlign::Left, true),
                new ReportColumn('nationality', __('Nationality'), ColumnAlign::Left, true),
                new ReportColumn('gender', __('Gender'), ColumnAlign::Left, true),
                new ReportColumn('date_of_birth', __('Date of birth'), ColumnAlign::Left, true),
                new ReportColumn('identity', __('ID'), ColumnAlign::Left, true),
                new ReportColumn('id_type', __('ID type'), ColumnAlign::Left, false),
                new ReportColumn('id_number', __('ID number'), ColumnAlign::Left, false),
                new ReportColumn('address', __('Address'), ColumnAlign::Left, true),
                new ReportColumn('phone', __('Phone'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('arrival_time', __('Arrival time'), ColumnAlign::Left, false),
                new ReportColumn('checked_in_at', __('Checked in'), ColumnAlign::Left, true),
                new ReportColumn('checked_in_date', __('Checked in date'), ColumnAlign::Left, false),
                new ReportColumn('checked_in_time', __('Checked in time'), ColumnAlign::Left, false),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('departure_time', __('Departure time'), ColumnAlign::Left, false),
                new ReportColumn('checked_out_at', __('Checked out'), ColumnAlign::Left, true),
                new ReportColumn('checked_out_date', __('Checked out date'), ColumnAlign::Left, false),
                new ReportColumn('checked_out_time', __('Checked out time'), ColumnAlign::Left, false),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
            ],
            self::HousekeepingTasks => [
                new ReportColumn('room', __('Room'), ColumnAlign::Left, true),
                new ReportColumn('room_group', __('Group'), ColumnAlign::Left, false),
                new ReportColumn('unit', __('Type / room'), ColumnAlign::Left, false),
                new ReportColumn('room_type', __('Room type'), ColumnAlign::Left, false),
                new ReportColumn('status', __('Status'), ColumnAlign::Left, true),
                new ReportColumn('assigned', __('Assigned'), ColumnAlign::Left, true),
                new ReportColumn('guest', __('Guest'), ColumnAlign::Left, true),
                new ReportColumn('arrival', __('Arrival'), ColumnAlign::Left, true),
                new ReportColumn('arrival_date', __('Arrival date'), ColumnAlign::Left, false),
                new ReportColumn('arrival_time', __('Arrival time'), ColumnAlign::Left, false),
                new ReportColumn('departure', __('Departure'), ColumnAlign::Left, true),
                new ReportColumn('departure_date', __('Departure date'), ColumnAlign::Left, false),
                new ReportColumn('departure_time', __('Departure time'), ColumnAlign::Left, false),
                new ReportColumn('flags', __('Flags'), ColumnAlign::Left, true),
                new ReportColumn('priority', __('Priority'), ColumnAlign::Left, false),
                new ReportColumn('do_not_disturb', __('Do not disturb'), ColumnAlign::Left, false),
                new ReportColumn('needs_maintenance', __('Maintenance'), ColumnAlign::Left, false),
                new ReportColumn('remarks', __('Remarks'), ColumnAlign::Left, true),
                new ReportColumn('remark', __('Remark'), ColumnAlign::Left, false),
                new ReportColumn('system_reason', __('Reason'), ColumnAlign::Left, false),
            ],
        };
    }

    /**
     * Whether the report is listed in the Reports sidebar. Housekeeping tasks is the board's print sheet, opened from
     * the Housekeeping page, and has no page of its own.
     */
    public function listedInReportsMenu(): bool
    {
        return $this !== self::HousekeepingTasks;
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
            self::OutstandingBalance => 'wallet',
            self::DepositLiability => 'shield',
            self::NonNationalGuests => 'users',
            self::PoliceReport => 'id-card',
            self::HousekeepingTasks => 'bed',
            self::BoatManifest => 'ship',
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
            self::OutstandingBalance => app(OutstandingBalanceReport::class),
            self::DepositLiability => app(DepositLiabilityReport::class),
            self::NonNationalGuests => app(NonNationalGuestsReport::class),
            self::PoliceReport => app(PoliceReport::class),
            self::HousekeepingTasks => app(HousekeepingTasksReport::class),
            self::BoatManifest => app(BoatManifestReport::class),
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
     * The note under the report title, or null when the report has none.
     */
    public function note(Property $property, ReportFilters $filters): ?string
    {
        $report = $this->report();

        return $report instanceof HasNote ? $report->note($property, $filters) : null;
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

    /**
     * The sections of the rows given, each with its heading and subtotal, or null when the report has none.
     *
     * @param  list<ReportRow>  $rows
     * @return list<ReportSection>|null
     */
    public function sections(array $rows, ReportFilters $filters): ?array
    {
        $report = $this->report();

        return $report instanceof HasSections ? $report->sections($rows, $filters) : null;
    }
}
