<?php

namespace App\Services\Group;

use App\Data\Group\GroupPropertyReportData;
use App\Data\Group\GroupReportRowData;
use App\Data\Property\CurrencyData;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportLayoutColumnData;
use App\Data\Reports\ReportRow;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Reports\RevenueChart;
use App\Services\Reports\RoomStatsChart;
use App\Services\Staff\GroupProperties;
use Illuminate\Database\Eloquent\Collection;

/**
 * Group › Property reports: one property report (`ReportKey::runsInGroup()`) run for every property of the group whose
 * role shows reports, with the property on each row. The rows are the report's own `rows()`, so they match the
 * property's report. Totals come from the report's own `totals()`, per property and, in one currency, for all.
 * See docs/03-staff/05-group-mode.md#property-reports.
 */
class GroupPropertyReports
{
    /** The reports whose row `key` is a reservation unit: a row opens the reservation panel. */
    private const array BOOKING_ROWS = [ReportKey::Arrivals, ReportKey::Departures, ReportKey::InHouse, ReportKey::CheckedOut];

    public function __construct(private GroupProperties $groupProperties, private RoomStatsChart $roomStatsChart, private RevenueChart $revenueChart) {}

    /**
     * @param  Collection<int, Property>  $properties  The properties the user may open in the group.
     */
    public function run(User $user, Collection $properties, ReportKey $key, ReportFilters $filters, bool $allRows = false, int $page = 1): GroupPropertyReportData
    {
        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::ReportsView, PermissionEnum::ReservationsView]);
        $allowed = $properties->filter(fn (Property $property): bool => in_array(PermissionEnum::ReportsView->value, $permissions[$property->id] ?? [], true))->values();
        $offered = $allowed->filter(fn (Property $property): bool => $key->isAvailable($property))->values();
        $oneCurrency = $offered->map(fn (Property $property): string => $property->settings->currency->getCurrencyCode())->unique()->count() === 1;

        $columns = [];
        $rows = [];
        $totals = [];
        $allReportRows = [];

        foreach ($offered as $property) {
            foreach ($key->columnsFor($property) as $column) {
                if ($column->defaultVisible) {
                    $columns[$column->key] ??= $column;
                }
            }
        }

        $layout = array_values(array_map(fn (ReportColumn $column): ReportLayoutColumnData => ReportLayoutColumnData::fromColumn($column, true), $columns));
        $filters = $key->groups() === [] ? $filters : new ReportFilters($filters->from, $filters->to, group: $filters->group ?? $key->groups()[0]);

        $rowsByProperty = [];
        $openableUnitIds = [];

        foreach ($offered as $property) {
            $rowsByProperty[$property->id] = $key->report()->rows($property, $filters);

            if (in_array($key, self::BOOKING_ROWS, true) && in_array(PermissionEnum::ReservationsView->value, $permissions[$property->id] ?? [], true)) {
                $openableUnitIds = [...$openableUnitIds, ...array_map(fn (ReportRow $row): int => (int) $row->key, $rowsByProperty[$property->id])];
            }
        }

        $reservations = $this->reservationsOfUnits($openableUnitIds);

        foreach ($offered as $property) {
            $reportRows = $rowsByProperty[$property->id];
            $allReportRows = [...$allReportRows, ...$reportRows];

            foreach ($reportRows as $row) {
                $rows[] = new GroupReportRowData($property->code, $property->name, $this->cells($row, $layout), reservationId: $reservations[(int) $row->key] ?? null);
            }

            $total = $key->totals($reportRows);

            if ($total !== null && $reportRows !== []) {
                $totals[] = new GroupReportRowData($property->code, $property->name, $total->cellsFor($layout), true);
            }
        }

        $all = $oneCurrency && count($offered) > 1 && $allReportRows !== [] ? $key->totals($allReportRows) : null;

        if ($all !== null) {
            $totals[] = new GroupReportRowData(null, __('All properties'), $all->cellsFor($layout), true);
        }

        $pages = max(1, (int) ceil(count($rows) / GroupPropertyReportData::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return new GroupPropertyReportData(
            report: $key->value,
            label: $key->label(),
            columns: $layout,
            rows: $allRows ? $rows : array_slice($rows, ($page - 1) * GroupPropertyReportData::PER_PAGE, GroupPropertyReportData::PER_PAGE),
            rowCount: count($rows),
            totals: $totals,
            currency: $oneCurrency && $offered->isNotEmpty() ? CurrencyData::forProperty($offered->first()) : null,
            leftOut: array_values($properties->reject(fn (Property $property): bool => $allowed->contains($property))->map(fn (Property $property): string => $property->name)->all()),
            notOffered: array_values($allowed->reject(fn (Property $property): bool => $offered->contains($property))->map(fn (Property $property): string => $property->name)->all()),
            page: $allRows ? 1 : $page,
            pages: $allRows ? 1 : $pages,
            roomStatsChart: ! $allRows && $oneCurrency && $allReportRows !== [] && $key === ReportKey::Occupancy ? $this->roomStatsChart->forGroup($allReportRows, $filters, $key) : null,
            revenueChart: ! $allRows && $oneCurrency && $allReportRows !== [] && $key === ReportKey::Revenue ? $this->revenueChart->forRows($allReportRows, $filters) : null,
        );
    }

    /**
     * The reservation of each unit, in one read for every property, for the rows of a front desk list. Only units of
     * properties whose role may view reservations are asked for, so a row elsewhere does not open the panel.
     *
     * @param  list<int>  $unitIds
     * @return array<int, int> Reservation id by unit id.
     */
    private function reservationsOfUnits(array $unitIds): array
    {
        return $unitIds === [] ? [] : ReservationUnit::query()->whereKey($unitIds)->pluck('reservation_id', 'id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The row's display text for every group column; a column the property does not have is blank.
     *
     * @param  list<ReportLayoutColumnData>  $layout
     * @return array<string, string>
     */
    private function cells(ReportRow $row, array $layout): array
    {
        $cells = $row->cells();

        return array_combine(
            array_map(fn (ReportLayoutColumnData $column): string => $column->key, $layout),
            array_map(fn (ReportLayoutColumnData $column): string => $cells[$column->key] ?? '', $layout),
        );
    }
}
