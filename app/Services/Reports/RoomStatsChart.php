<?php

namespace App\Services\Reports;

use App\Data\Reports\HistoryForecastRow;
use App\Data\Reports\OccupancyRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;
use App\Data\Reports\RoomStatsChartData;
use App\Data\Reports\RoomStatsChartPointData;
use App\Enums\ForecastBasis;
use App\Enums\ReportGroup;
use App\Enums\ReportKey;
use Carbon\CarbonImmutable;

/**
 * The chart of the Occupancy and History & forecast reports, from the rows the table already built (every row of the
 * range, not one page). A day is the row itself. A range longer than `DAILY_UP_TO_DAYS` has one point per month, and a
 * month's figures are the report's `totals()` over its rows, the same math as the table's total row.
 * See docs/22-reports/06-report-charts.md.
 */
class RoomStatsChart
{
    /** Up to this many days the chart has a bar per day; a longer range has one per month. */
    public const int DAILY_UP_TO_DAYS = 62;

    /**
     * @param  list<ReportRow>  $rows
     */
    public function occupancy(array $rows, ReportFilters $filters): RoomStatsChartData
    {
        $rows = array_values(array_filter($rows, fn (ReportRow $row): bool => $row instanceof OccupancyRow));

        return $this->chart($rows, $filters, ReportKey::Occupancy, array_map(fn (OccupancyRow $row): RoomStatsChartPointData => new RoomStatsChartPointData(
            date: $row->date,
            basis: $row->live ? ForecastBasis::Today : ForecastBasis::Actual,
            occupancy: $row->occupancy,
            adr: $row->adr,
            revpar: $row->revpar,
            revenue: $row->revenue,
        ), $rows), 'revenue');
    }

    /**
     * @param  list<ReportRow>  $rows
     */
    public function historyForecast(array $rows, ReportFilters $filters): RoomStatsChartData
    {
        $rows = array_values(array_filter($rows, fn (ReportRow $row): bool => $row instanceof HistoryForecastRow));

        return $this->chart($rows, $filters, ReportKey::HistoryForecast, array_map(fn (HistoryForecastRow $row): RoomStatsChartPointData => new RoomStatsChartPointData(
            date: $row->date,
            basis: ForecastBasis::from($row->basis->value),
            occupancy: $row->occupancy,
            adr: $row->adr,
            revpar: $row->revpar,
            revenue: $row->room_charges,
        ), $rows), 'room_charges');
    }

    /**
     * The chart of several properties' rows of one report (Occupancy or History & forecast) at once, for group mode:
     * one point per date (or per month past `DAILY_UP_TO_DAYS`), each the report's `totals()` over every property's
     * rows of that date or month, so rates come from the sums, never averaged.
     *
     * @param  list<ReportRow>  $rows
     */
    public function forGroup(array $rows, ReportFilters $filters, ReportKey $report): RoomStatsChartData
    {
        $days = $report === ReportKey::Occupancy ? $this->occupancy($rows, $filters) : $this->historyForecast($rows, $filters);
        $length = (int) CarbonImmutable::parse($filters->from)->diffInDays(CarbonImmutable::parse($filters->to)) + 1;
        $rows = array_values(array_filter($rows, fn (ReportRow $row): bool => $row instanceof OccupancyRow || $row instanceof HistoryForecastRow));
        $keyOf = fn (string $date): string => $length <= self::DAILY_UP_TO_DAYS ? $date : substr($date, 0, 7);
        $revenueField = $report === ReportKey::Occupancy ? 'revenue' : 'room_charges';

        /** @var array<string, list<OccupancyRow|HistoryForecastRow>> $buckets */
        $buckets = [];
        /** @var array<string, list<string>> $bases */
        $bases = [];

        foreach ($rows as $row) {
            $key = $keyOf($row->date);
            $buckets[$key][] = $row;
        }

        foreach ($days->points as $point) {
            $bases[$keyOf($point->date)][] = $point->basis->value;
        }

        ksort($buckets);
        $points = [];

        foreach ($buckets as $key => $bucket) {
            $figures = $report->totals($bucket)->figures ?? [];
            $kinds = array_values(array_unique($bases[$key] ?? []));
            $points[] = new RoomStatsChartPointData(
                date: (string) $key,
                basis: count($kinds) === 1 ? ForecastBasis::from($kinds[0]) : ForecastBasis::Today,
                occupancy: (string) ($figures['occupancy'] ?? '0.00'),
                adr: (string) ($figures['adr'] ?? '0.00'),
                revpar: (string) ($figures['revpar'] ?? '0.00'),
                revenue: (string) ($figures[$revenueField] ?? '0.00'),
            );
        }

        return new RoomStatsChartData($length <= self::DAILY_UP_TO_DAYS ? ReportGroup::Day : ReportGroup::Month, $points);
    }

    /**
     * The day points as given, or one point per month: the report's totals over the month's rows, Actual or Forecast
     * when every day of the month is, else Today.
     *
     * @param  list<OccupancyRow>|list<HistoryForecastRow>  $rows
     * @param  list<RoomStatsChartPointData>  $days  One point per row, in the order of `$rows`.
     * @param  string  $revenueField  The total figure that holds the room revenue.
     */
    private function chart(array $rows, ReportFilters $filters, ReportKey $report, array $days, string $revenueField): RoomStatsChartData
    {
        $order = array_keys($days);
        usort($order, fn (int $a, int $b): int => strcmp($days[$a]->date, $days[$b]->date));
        $length = (int) CarbonImmutable::parse($filters->from)->diffInDays(CarbonImmutable::parse($filters->to)) + 1;

        if ($length <= self::DAILY_UP_TO_DAYS) {
            return new RoomStatsChartData(ReportGroup::Day, array_map(fn (int $index): RoomStatsChartPointData => $days[$index], $order));
        }

        /** @var array<string, list<int>> $months */
        $months = [];

        foreach ($order as $index) {
            $months[substr($days[$index]->date, 0, 7)][] = $index;
        }

        $points = [];

        foreach ($months as $month => $indexes) {
            $figures = $report->totals(array_map(fn (int $index): OccupancyRow|HistoryForecastRow => $rows[$index], $indexes))->figures ?? [];
            $bases = array_values(array_unique(array_map(fn (int $index): string => $days[$index]->basis->value, $indexes)));
            $points[] = new RoomStatsChartPointData(
                date: (string) $month,
                basis: count($bases) === 1 ? ForecastBasis::from($bases[0]) : ForecastBasis::Today,
                occupancy: (string) ($figures['occupancy'] ?? '0.00'),
                adr: (string) ($figures['adr'] ?? '0.00'),
                revpar: (string) ($figures['revpar'] ?? '0.00'),
                revenue: (string) ($figures[$revenueField] ?? '0.00'),
            );
        }

        return new RoomStatsChartData(ReportGroup::Month, $points);
    }
}
