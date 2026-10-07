<?php

namespace App\Services\Reports;

use App\Data\Reports\OccupancyRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\StatisticsCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One row for each business date in the range. A closed date reads its `daily_statistics` snapshot, which the night
 * audit writes once and never changes. The open business date is worked out now by `StatisticsCalculator` and is
 * marked live. A date after the open date has no figures and is left out, and so is a closed date with no snapshot
 * (before go-live). Room revenue is read from `DailyRevenueQuery` for every date: a closed date cannot take new
 * postings, so it equals what the audit saw. Rooms and beds count as one unit each. See
 * docs/22-reports/01-core-reports.md.
 */
class OccupancyReport implements HasTotals, Report
{
    public function __construct(
        private CurrentBusinessDate $businessDate,
        private StatisticsCalculator $calculator,
        private DailyRevenueQuery $revenue,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<OccupancyRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        $open = $this->businessDate->date($property);
        $lastClosed = $open === null ? $to : $open->subDay()->min($to);
        $lastWithFigures = $open === null ? $to : $open->min($to);

        $revenue = $this->revenue->roomRevenueBetween($property, $from, $lastWithFigures);
        $zero = Money::zero($property->loadMissing('settings')->settings->currency->value);

        $rows = DailyStatistic::query()
            ->where('property_id', $property->id)
            ->whereBetween('business_date', [$from->toDateString(), $lastClosed->toDateString()])
            ->orderBy('business_date')
            ->get()
            ->map(fn (DailyStatistic $statistic): OccupancyRow => OccupancyRow::fromStatistic(
                $statistic,
                $revenue[$statistic->business_date->toDateString()] ?? $zero,
            ))
            ->all();

        if ($open !== null && $open->betweenIncluded($from, $to)) {
            $rows[] = OccupancyRow::fromFigures(
                $open,
                $this->calculator->forDate($property, $open),
                $revenue[$open->toDateString()] ?? $zero,
            );
        }

        if ($rowKeys !== null) {
            $wanted = array_map('strval', $rowKeys);
            $rows = array_values(array_filter($rows, fn (OccupancyRow $row): bool => in_array($row->key, $wanted, true)));
        }

        return $rows;
    }

    /**
     * Sums of the units, the revenue and the front desk counts. Occupancy, ADR and RevPAR come from the sums. In-house
     * guests have no total: each date counts the same guests again.
     *
     * @param  list<OccupancyRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $available = array_sum(array_map(fn (OccupancyRow $row): int => $row->available, $rows));
        $sold = array_sum(array_map(fn (OccupancyRow $row): int => $row->sold, $rows));
        $revenue = array_reduce($rows, fn (BigDecimal $sum, OccupancyRow $row): BigDecimal => $sum->plus($row->revenue), BigDecimal::zero());

        return new ReportTotals([
            'date' => null,
            'available' => $available,
            'sold' => $sold,
            'occupancy' => $available === 0 ? '0.00' : (string) BigDecimal::of($sold)->multipliedBy(100)->dividedBy($available, 2, RoundingMode::HalfUp),
            'adr' => $sold === 0 ? '0.00' : (string) $revenue->dividedBy($sold, 2, RoundingMode::HalfUp),
            'revpar' => $available === 0 ? '0.00' : (string) $revenue->dividedBy($available, 2, RoundingMode::HalfUp),
            'revenue' => (string) $revenue->toScale(2, RoundingMode::HalfUp),
            'arrivals' => array_sum(array_map(fn (OccupancyRow $row): int => $row->arrivals, $rows)),
            'departures' => array_sum(array_map(fn (OccupancyRow $row): int => $row->departures, $rows)),
            'noshows' => array_sum(array_map(fn (OccupancyRow $row): int => $row->noshows, $rows)),
            'guests' => null,
        ]);
    }
}
