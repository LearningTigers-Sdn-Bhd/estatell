<?php

namespace App\Services\Group;

use App\Data\Group\GroupForecastData;
use App\Data\Group\GroupForecastRowData;
use App\Data\Property\CurrencyData;
use App\Data\Property\PropertySummaryData;
use App\Data\Reports\HistoryForecastRow;
use App\Data\Reports\ReportFilters;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use App\Services\Reports\HistoryForecastReport;
use App\Services\Reports\ReportFigures;
use App\Services\Staff\GroupProperties;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The group forecast: each property's History & forecast for the months asked, added up by month, beside the same
 * months a year before. Closed dates are actual and later dates are what the bookings on hand bring, each property by
 * its own business date. Counts and money are summed; occupancy, ADR and RevPAR are worked out again from the sums.
 * Queries grow with the number of properties, never with bookings or months. See docs/03-staff/05-group-mode.md#forecast.
 */
class GroupForecast
{
    /** The longest forecast, in months. */
    public const int MAX_MONTHS = 24;

    public function __construct(
        private GroupProperties $groupProperties,
        private HistoryForecastReport $historyForecast,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties  The properties the user may open in the group.
     */
    public function for(User $user, Collection $properties, CarbonImmutable $firstMonth, int $months): GroupForecastData
    {
        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::ReportsView]);
        $visible = $properties->filter(fn (Property $property): bool => in_array(PermissionEnum::ReportsView->value, $permissions[$property->id] ?? [], true))->values();
        $leftOut = array_values($properties->reject(fn (Property $property): bool => $visible->contains($property))->map(fn (Property $property): string => $property->name)->all());
        $oneCurrency = $visible->map(fn (Property $property): string => $property->settings->currency->getCurrencyCode())->unique()->count() === 1;

        $from = $firstMonth->startOfMonth();
        $to = $from->addMonthsNoOverflow($months - 1)->endOfMonth();
        $monthKeys = [];

        for ($month = $from; $month->lte($to); $month = $month->addMonthNoOverflow()) {
            $monthKeys[] = $month->format('Y-m');
        }

        $rows = [];
        $allThisYear = [];
        $allLastYear = [];

        foreach ($visible as $property) {
            $thisYear = $this->byMonth($this->historyForecast->rows($property, new ReportFilters($from->toDateString(), $to->toDateString())), 0);
            $lastYear = $this->byMonth($this->historyForecast->rows($property, new ReportFilters($from->subYear()->toDateString(), $to->subYear()->endOfMonth()->toDateString())), 1);

            foreach ($monthKeys as $month) {
                $rows[] = $this->row($month, $property->code, $thisYear[$month] ?? [], $lastYear[$month] ?? [], true);
                $allThisYear[$month] = [...($allThisYear[$month] ?? []), ...($thisYear[$month] ?? [])];
                $allLastYear[$month] = [...($allLastYear[$month] ?? []), ...($lastYear[$month] ?? [])];
            }
        }

        foreach ($monthKeys as $month) {
            $rows[] = $this->row($month, null, $allThisYear[$month] ?? [], $allLastYear[$month] ?? [], $oneCurrency);
        }

        return new GroupForecastData(
            months: $monthKeys,
            properties: array_values($visible->map(fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property))->all()),
            rows: $rows,
            currency: $oneCurrency && $visible->isNotEmpty() ? CurrencyData::forProperty($visible->first()) : null,
            leftOut: $leftOut,
        );
    }

    /**
     * The rows by month (`Y-m`). Last year's rows move forward a year so they line up with this year's months, and only
     * its closed (actual) dates count: a month a year back that is still ahead has no last year figures.
     *
     * @param  list<HistoryForecastRow>  $rows
     * @return array<string, list<HistoryForecastRow>>
     */
    private function byMonth(array $rows, int $yearsBack): array
    {
        $months = [];

        foreach ($rows as $row) {
            if ($yearsBack > 0 && $row->basis->value !== 'actual') {
                continue;
            }

            $months[CarbonImmutable::parse($row->date)->addYears($yearsBack)->format('Y-m')][] = $row;
        }

        return $months;
    }

    /**
     * @param  list<HistoryForecastRow>  $thisYear
     * @param  list<HistoryForecastRow>  $lastYear
     */
    private function row(string $month, ?string $code, array $thisYear, array $lastYear, bool $withMoney): GroupForecastRowData
    {
        $count = fn (array $rows, string $field): int => array_sum(array_map(fn (HistoryForecastRow $row): int => $row->{$field}, $rows));
        $money = fn (array $rows, string $field): BigDecimal => array_reduce($rows, fn (BigDecimal $sum, HistoryForecastRow $row): BigDecimal => $sum->plus($row->{$field} ?? '0'), BigDecimal::zero());
        $available = $count($thisYear, 'available');
        $sold = $count($thisYear, 'sold');
        $paid = $sold - $count($thisYear, 'complimentary');
        $room = $money($thisYear, 'room_charges');
        $extra = $money($thisYear, 'extra_charges');
        $bases = array_unique(array_map(fn (HistoryForecastRow $row): string => $row->basis->value === 'actual' ? 'actual' : 'forecast', $thisYear));
        $lastYearAvailable = $count($lastYear, 'available');

        return new GroupForecastRowData(
            month: $month,
            code: $code,
            basis: count($bases) === 1 ? $bases[array_key_first($bases)] : ($bases === [] ? 'forecast' : 'mixed'),
            available: $available,
            sold: $sold,
            occupancy: ReportFigures::percent($sold, $available),
            adr: $withMoney ? ReportFigures::perUnit($room, $paid) : null,
            revpar: $withMoney ? ReportFigures::perUnit($room, $available) : null,
            pax: $count($thisYear, 'pax'),
            roomRevenue: $withMoney ? ReportFigures::money($room) : null,
            extraRevenue: $withMoney ? ReportFigures::money($extra) : null,
            totalRevenue: $withMoney ? ReportFigures::money($room->plus($extra)) : null,
            lastYearAvailable: $lastYear === [] ? null : $lastYearAvailable,
            lastYearSold: $lastYear === [] ? null : $count($lastYear, 'sold'),
            lastYearOccupancy: $lastYear === [] ? null : ReportFigures::percent($count($lastYear, 'sold'), $lastYearAvailable),
            lastYearRoomRevenue: $lastYear === [] || ! $withMoney ? null : ReportFigures::money($money($lastYear, 'room_charges')),
        );
    }
}
