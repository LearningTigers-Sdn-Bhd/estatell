<?php

namespace App\Services\Group;

use App\Data\Group\GroupBookingChangeRowData;
use App\Data\Group\GroupFiguresRowData;
use App\Data\Group\GroupReportsData;
use App\Data\Property\CurrencyData;
use App\Data\Property\PropertySummaryData;
use App\Data\Reports\HistoryForecastRow;
use App\Data\Reports\ReportFilters;
use App\Enums\GroupReportTab;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use App\Services\Reports\BookingChangesReport;
use App\Services\Reports\ReportFigures;
use App\Services\Staff\GroupProperties;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Group reports: the properties' own reports, run for each property of the group whose role there shows reports and
 * added up. By property is each property's History & forecast total; By date adds the properties up date by date
 * (each date is actual or forecast at each property by its own business date); Bookings is every property's Rooms on
 * books › Changes with the property named. Counts and money are summed; occupancy, ADR and RevPAR are worked out
 * again from the sums. Money is summed only in one currency. Queries grow with the number of properties, never with
 * bookings. See docs/03-staff/05-group-mode.md#group-reports.
 */
class GroupReports
{
    public function __construct(
        private GroupProperties $groupProperties,
        private ForecastMonthCache $monthCache,
        private BookingChangesReport $bookingChanges,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties  The properties the user may open in the group.
     */
    public function for(User $user, Collection $properties, GroupReportTab $tab, ReportFilters $filters): GroupReportsData
    {
        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::ReportsView, PermissionEnum::ReservationsView]);
        $holds = fn (Property $property, PermissionEnum $permission): bool => in_array($permission->value, $permissions[$property->id] ?? [], true);
        $visible = $properties->filter(fn (Property $property): bool => $holds($property, PermissionEnum::ReportsView))->values();
        $currencies = $visible->map(fn (Property $property): string => $property->settings->currency->getCurrencyCode())->unique();
        $oneCurrency = $currencies->count() === 1;
        $summaries = array_values($visible->map(fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property))->all());
        $currency = $oneCurrency ? CurrencyData::forProperty($visible->first()) : null;
        $leftOut = array_values($properties->reject(fn (Property $property): bool => $holds($property, PermissionEnum::ReportsView))->map(fn (Property $property): string => $property->name)->all());

        if ($tab === GroupReportTab::Bookings) {
            return new GroupReportsData($summaries, $currency, [], null, $this->bookings($visible, $filters, fn (Property $property): bool => $holds($property, PermissionEnum::ReservationsView)), $leftOut);
        }

        $rowsByProperty = [];

        foreach ($visible as $property) {
            $rowsByProperty[$property->id] = $this->monthCache->between($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to));
        }

        $rows = $tab === GroupReportTab::ByProperty
            ? $this->byProperty($visible, $rowsByProperty, $oneCurrency)
            : $this->byDate($rowsByProperty, $oneCurrency);
        $all = array_merge(...array_values($rowsByProperty));

        return new GroupReportsData($summaries, $currency, $rows, $all === [] ? null : $this->combine(__('All properties'), null, $all, $oneCurrency), [], $leftOut);
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @param  array<int, list<HistoryForecastRow>>  $rowsByProperty
     * @return list<GroupFiguresRowData>
     */
    private function byProperty(Collection $properties, array $rowsByProperty, bool $oneCurrency): array
    {
        return array_values($properties
            ->map(fn (Property $property): GroupFiguresRowData => $this->combine($property->name, $property->code, $rowsByProperty[$property->id], true))
            ->all());
    }

    /**
     * @param  array<int, list<HistoryForecastRow>>  $rowsByProperty
     * @return list<GroupFiguresRowData>
     */
    private function byDate(array $rowsByProperty, bool $oneCurrency): array
    {
        $byDate = [];

        foreach ($rowsByProperty as $rows) {
            foreach ($rows as $row) {
                $byDate[$row->date][] = $row;
            }
        }

        ksort($byDate);

        return array_map(fn (string $date): GroupFiguresRowData => $this->combine($date, null, $byDate[$date], $oneCurrency), array_keys($byDate));
    }

    /**
     * @param  list<HistoryForecastRow>  $rows
     */
    private function combine(string $label, ?string $code, array $rows, bool $withMoney): GroupFiguresRowData
    {
        $count = fn (string $field): int => array_sum(array_map(fn (HistoryForecastRow $row): int => $row->{$field}, $rows));
        $money = fn (string $field): BigDecimal => array_reduce($rows, fn (BigDecimal $sum, HistoryForecastRow $row): BigDecimal => $sum->plus($row->{$field} ?? '0'), BigDecimal::zero());
        $available = $count('available');
        $sold = $count('sold');
        $complimentary = $count('complimentary');
        $room = $money('room_charges');

        return new GroupFiguresRowData(
            label: $label,
            code: $code,
            available: $available,
            sold: $sold,
            complimentary: $complimentary,
            occupancy: ReportFigures::percent($sold, $available),
            adr: $withMoney ? ReportFigures::perUnit($room, $sold - $complimentary) : null,
            revpar: $withMoney ? ReportFigures::perUnit($room, $available) : null,
            pax: $count('pax'),
            roomCharges: $withMoney ? ReportFigures::money($room) : null,
            extraCharges: $withMoney ? ReportFigures::money($money('extra_charges')) : null,
            tax: $withMoney ? ReportFigures::money($money('tax')) : null,
            receipts: $withMoney ? ReportFigures::money($money('receipts')) : null,
        );
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @param  \Closure(Property): bool  $canOpen  Whether the role at the property may view its reservations.
     * @return list<GroupBookingChangeRowData>
     */
    private function bookings(Collection $properties, ReportFilters $filters, \Closure $canOpen): array
    {
        $rows = [];

        foreach ($properties as $property) {
            $opens = $canOpen($property);

            foreach ($this->bookingChanges->rows($property, $filters) as $change) {
                $rows[] = new GroupBookingChangeRowData($property->code, $property->name, $change, $opens);
            }
        }

        usort($rows, fn (GroupBookingChangeRowData $a, GroupBookingChangeRowData $b): int => [$a->change->changed_at, $a->propertyName] <=> [$b->change->changed_at, $b->propertyName]);

        return $rows;
    }
}
