<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\ChannelMixCompanyData;
use App\Data\Dashboard\ChannelMixRowData;
use App\Data\Dashboard\LeadTimeBucketData;
use App\Data\Dashboard\LeadTimeData;
use App\Data\Dashboard\PerformanceChannelMixData;
use App\Data\Dashboard\PerformanceKpiData;
use App\Data\Dashboard\PerformanceSummaryData;
use App\Data\Dashboard\PerformanceTrendPointData;
use App\Data\Property\CurrencyData;
use App\Data\Reports\OccupancyRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reservations\BookingSourceBadgeData;
use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Enums\UnitStatus;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Services\Reports\OccupancyReport;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the dashboard Performance tab for one period and the range it is compared with. It reads only.
 *
 * - Occupancy, ADR and RevPAR come from `OccupancyReport::rows()` and `totals()`, so the tab and the report never
 *   disagree for the same dates. Day use stays out, as in the report.
 * - Lead time and channel mix are one grouped query each for a range, so the number of queries does not grow with the
 *   length of the period.
 *
 * See docs/18-front-desk/06-dashboard-performance.md.
 */
class PerformanceSummary
{
    /** @var list<string> */
    private const LEAD_BUCKETS = ['same-day', '1-7', '8-30', '31-plus'];

    public function __construct(
        private OccupancyReport $report,
        private ShareRounder $shares,
    ) {}

    public function forPeriod(Property $property, PerformancePeriod $period): PerformanceSummaryData
    {
        $property->loadMissing('settings');

        $current = $this->occupancyRows($property, $period->from, $period->to);
        $previous = $this->occupancyRows($property, $period->previousFrom, $period->previousTo);
        $currentFigures = $this->figures($current);
        $previousFigures = $this->figures($previous);

        $lead = $this->leadCounts($property, $period->from, $period->to);
        $previousLead = $this->leadCounts($property, $period->previousFrom, $period->previousTo);
        $channelMix = $this->channelMix($property, $period->from, $period->to);

        $hasStays = $channelMix->nights > 0 || $lead['units'] > 0;
        $hasAuditFigures = $currentFigures['available'] > 0;
        $showFigures = $hasStays && $hasAuditFigures;

        return new PerformanceSummaryData(
            currency: CurrencyData::forProperty($property),
            hasStays: $hasStays,
            hasAuditFigures: $hasAuditFigures,
            occupancy: $this->kpi($showFigures, $currentFigures, $previousFigures, 'occupancy'),
            adr: $this->kpi($showFigures, $currentFigures, $previousFigures, 'adr'),
            revpar: $this->kpi($showFigures, $currentFigures, $previousFigures, 'revpar'),
            leadTime: $this->leadTime($lead, $previousLead),
            channelMix: $channelMix,
            trend: $this->trend($period, $current, $previous),
        );
    }

    /**
     * The first date with data: the earlier of the first night statistic and the first night of a live unit. Null for a
     * property with neither. It sets where the month picker starts.
     */
    public function earliestDate(Property $property): ?CarbonImmutable
    {
        $statistic = DailyStatistic::query()->where('property_id', $property->id)->min('business_date');
        $night = DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->where('reservation_units.property_id', $property->id)
            ->whereIn('reservation_units.status', $this->liveStatuses())
            ->min('unit_nights.date');

        $dates = array_filter([$statistic, $night]);

        if ($dates === []) {
            return null;
        }

        return CarbonImmutable::parse(min(array_map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString(), $dates)));
    }

    /**
     * @return list<OccupancyRow>
     */
    private function occupancyRows(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->report->rows($property, new ReportFilters($from->toDateString(), $to->toDateString()));
    }

    /**
     * The sums of the rows, as the report totals work them out.
     *
     * @param  list<OccupancyRow>  $rows
     * @return array{available: int, sold: int, revenue: BigDecimal, occupancy: string|null, adr: string|null, revpar: string|null}
     */
    private function figures(array $rows): array
    {
        $totals = $this->report->totals($rows)->figures;
        $available = (int) $totals['available'];
        $sold = (int) $totals['sold'];

        return [
            'available' => $available,
            'sold' => $sold,
            'revenue' => BigDecimal::of((string) $totals['revenue']),
            'occupancy' => $available === 0 ? null : (string) $totals['occupancy'],
            'adr' => $sold === 0 ? null : (string) $totals['adr'],
            'revpar' => $available === 0 ? null : (string) $totals['revpar'],
        ];
    }

    /**
     * @param  array{available: int, sold: int, revenue: BigDecimal, occupancy: string|null, adr: string|null, revpar: string|null}  $current
     * @param  array{available: int, sold: int, revenue: BigDecimal, occupancy: string|null, adr: string|null, revpar: string|null}  $previous
     */
    private function kpi(bool $show, array $current, array $previous, string $key): PerformanceKpiData
    {
        $value = $show ? $this->figure($current, $key) : null;
        $before = $this->figure($previous, $key);

        if ($value === null) {
            return new PerformanceKpiData(null, null, null);
        }

        $hidden = $previous['available'] === 0 || $before === null;

        return new PerformanceKpiData(
            $value,
            $hidden ? null : $before,
            $hidden ? null : $this->change($key, $current, $previous),
        );
    }

    /**
     * @param  array{available: int, sold: int, revenue: BigDecimal, occupancy: string|null, adr: string|null, revpar: string|null}  $figures
     */
    private function figure(array $figures, string $key): ?string
    {
        return match ($key) {
            'occupancy' => $figures['occupancy'],
            'adr' => $figures['adr'],
            default => $figures['revpar'],
        };
    }

    /**
     * Occupancy in points; ADR and RevPAR in percent. Worked out from the sums, not from the rounded figures.
     *
     * @param  array{available: int, sold: int, revenue: BigDecimal, occupancy: string|null, adr: string|null, revpar: string|null}  $current
     * @param  array{available: int, sold: int, revenue: BigDecimal, occupancy: string|null, adr: string|null, revpar: string|null}  $previous
     */
    private function change(string $key, array $current, array $previous): ?string
    {
        $value = fn (array $figures): ?BigDecimal => match ($key) {
            'occupancy' => $figures['available'] === 0 ? null : BigDecimal::of($figures['sold'])->multipliedBy(100)->dividedBy($figures['available'], 8, RoundingMode::HalfUp),
            'adr' => $figures['sold'] === 0 ? null : $figures['revenue']->dividedBy($figures['sold'], 8, RoundingMode::HalfUp),
            default => $figures['available'] === 0 ? null : $figures['revenue']->dividedBy($figures['available'], 8, RoundingMode::HalfUp),
        };

        $now = $value($current);
        $before = $value($previous);

        if ($now === null || $before === null) {
            return null;
        }

        if ($key === 'occupancy') {
            return (string) $now->minus($before)->toScale(2, RoundingMode::HalfUp);
        }

        return $before->isZero() ? null : (string) $now->minus($before)->multipliedBy(100)->dividedBy($before, 2, RoundingMode::HalfUp);
    }

    /**
     * One point for each day of the range, or each month for a 12 month range, against the same step of the compared
     * range.
     *
     * @param  list<OccupancyRow>  $current
     * @param  list<OccupancyRow>  $previous
     * @return list<PerformanceTrendPointData>
     */
    private function trend(PerformancePeriod $period, array $current, array $previous): array
    {
        $step = $period->groupsByMonth ? 'month' : 'day';
        $format = $period->groupsByMonth ? 'Y-m' : 'Y-m-d';
        $now = $this->groupRows($current, $format);
        $before = $this->groupRows($previous, $format);

        $points = [];
        $date = $period->groupsByMonth ? $period->from->startOfMonth() : $period->from;
        $count = $period->groupsByMonth ? (int) $period->from->startOfMonth()->diffInMonths($period->to->startOfMonth()) + 1 : (int) $period->from->diffInDays($period->to) + 1;

        for ($index = 0; $index < $count; $index++) {
            $key = $this->step($date, $step, $index)->format($format);
            $previousDate = $this->step($period->groupsByMonth ? $period->previousFrom->startOfMonth() : $period->previousFrom, $step, $index);
            $previousKey = $previousDate->lte($period->previousTo) ? $previousDate->format($format) : null;
            $nowFigures = isset($now[$key]) ? $this->figures($now[$key]) : null;
            $beforeFigures = $previousKey !== null && isset($before[$previousKey]) ? $this->figures($before[$previousKey]) : null;

            $points[] = new PerformanceTrendPointData(
                date: $key,
                occupancy: $this->number($nowFigures, 'occupancy'),
                adr: $this->number($nowFigures, 'adr'),
                revpar: $this->number($nowFigures, 'revpar'),
                previousDate: $previousKey,
                previousOccupancy: $this->number($beforeFigures, 'occupancy'),
                previousAdr: $this->number($beforeFigures, 'adr'),
                previousRevpar: $this->number($beforeFigures, 'revpar'),
            );
        }

        return $points;
    }

    private function step(CarbonImmutable $start, string $step, int $index): CarbonImmutable
    {
        return $step === 'month' ? $start->addMonthsNoOverflow($index) : $start->addDays($index);
    }

    /**
     * @param  list<OccupancyRow>  $rows
     * @return array<string, list<OccupancyRow>>
     */
    private function groupRows(array $rows, string $format): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[CarbonImmutable::parse($row->date)->format($format)][] = $row;
        }

        return $groups;
    }

    /**
     * @param  array{occupancy: string|null, adr: string|null, revpar: string|null}|null  $figures
     */
    private function number(?array $figures, string $key): ?float
    {
        return $figures === null || $figures[$key] === null ? null : (float) $figures[$key];
    }

    /**
     * Days from booking to arrival of the live units arriving in the range. The booking time is read in the property
     * time zone as a date; a negative result counts as 0. Walk-ins are in `units` but left out of everything else.
     *
     * @return array{units: int, counted: int, days: int, buckets: list<int>}
     */
    private function leadCounts(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timeZone = $property->settings->time_zone ?? config('app.timezone');

        $units = DB::table('reservation_units')
            ->join('reservations', 'reservations.id', '=', 'reservation_units.reservation_id')
            ->join('booking_sources', 'booking_sources.id', '=', 'reservations.booking_source_id')
            ->where('reservation_units.property_id', $property->id)
            ->whereIn('reservation_units.status', $this->liveStatuses())
            ->whereBetween('reservation_units.arrival_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                '(booking_sources.type = ?) as is_walk_in, GREATEST(0, reservation_units.arrival_date - ((reservations.created_at AT TIME ZONE ?) AT TIME ZONE ?)::date) as days',
                [BookingSourceType::WalkIn->value, config('app.timezone'), $timeZone],
            );

        $row = DB::query()->fromSub($units, 'leads')
            ->selectRaw(<<<'SQL'
                COUNT(*) as units,
                COUNT(*) FILTER (WHERE NOT is_walk_in) as counted,
                COALESCE(SUM(days) FILTER (WHERE NOT is_walk_in), 0) as total_days,
                COUNT(*) FILTER (WHERE NOT is_walk_in AND days = 0) as same_day,
                COUNT(*) FILTER (WHERE NOT is_walk_in AND days BETWEEN 1 AND 7) as week,
                COUNT(*) FILTER (WHERE NOT is_walk_in AND days BETWEEN 8 AND 30) as month,
                COUNT(*) FILTER (WHERE NOT is_walk_in AND days >= 31) as longer
                SQL)
            ->first();

        return [
            'units' => (int) $row->units,
            'counted' => (int) $row->counted,
            'days' => (int) $row->total_days,
            'buckets' => [(int) $row->same_day, (int) $row->week, (int) $row->month, (int) $row->longer],
        ];
    }

    /**
     * @param  array{units: int, counted: int, days: int, buckets: list<int>}  $lead
     * @param  array{units: int, counted: int, days: int, buckets: list<int>}  $previous
     */
    private function leadTime(array $lead, array $previous): LeadTimeData
    {
        $average = fn (array $counts): ?BigDecimal => $counts['counted'] === 0 ? null : BigDecimal::of($counts['days'])->dividedBy($counts['counted'], 8, RoundingMode::HalfUp);
        $now = $average($lead);
        $before = $average($previous);
        $shares = $this->shares->percentages($lead['buckets']);

        $buckets = [];

        foreach (self::LEAD_BUCKETS as $index => $key) {
            $buckets[] = new LeadTimeBucketData($key, $lead['buckets'][$index], $shares[$index]);
        }

        return new LeadTimeData(
            average: new PerformanceKpiData(
                $now === null ? null : (string) $now->toScale(0, RoundingMode::HalfUp),
                $before === null ? null : (string) $before->toScale(0, RoundingMode::HalfUp),
                $now === null || $before === null ? null : (string) $now->minus($before)->toScale(2, RoundingMode::HalfUp),
            ),
            units: $lead['counted'],
            buckets: $buckets,
        );
    }

    /**
     * Room nights of live units by stay date, by source and by type, from one query.
     */
    private function channelMix(Property $property, CarbonImmutable $from, CarbonImmutable $to): PerformanceChannelMixData
    {
        $rows = DB::table('unit_nights')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_nights.reservation_unit_id')
            ->join('reservations', 'reservations.id', '=', 'reservation_units.reservation_id')
            ->join('booking_sources', 'booking_sources.id', '=', 'reservations.booking_source_id')
            ->leftJoin('companies', 'companies.id', '=', 'reservations.company_id')
            ->where('reservation_units.property_id', $property->id)
            ->whereIn('reservation_units.status', $this->liveStatuses())
            ->whereBetween('unit_nights.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('booking_sources.id', 'companies.id')
            ->selectRaw('booking_sources.id as source_id, booking_sources.name as source_name, booking_sources.type as source_type, booking_sources.badge_icon, booking_sources.badge_letters, booking_sources.badge_color, companies.id as company_id, companies.name as company_name, COUNT(*) as nights, COALESCE(SUM(unit_nights.amount), 0) as amount')
            ->get();

        /** @var array<int, array{row: array<string, mixed>, nights: int, amount: BigDecimal, companies: array<int, array{name: string, nights: int}>}> $sources */
        $sources = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (int) $row['source_id'];
            $source = $sources[$id] ?? ['row' => $row, 'nights' => 0, 'amount' => BigDecimal::zero(), 'companies' => []];
            $source['nights'] += (int) $row['nights'];
            $source['amount'] = $source['amount']->plus((string) $row['amount']);

            if ($row['company_id'] !== null && $row['source_type'] === BookingSourceType::Company->value) {
                $companyId = (int) $row['company_id'];
                $source['companies'][$companyId] = [
                    'name' => (string) $row['company_name'],
                    'nights' => ($source['companies'][$companyId]['nights'] ?? 0) + (int) $row['nights'],
                ];
            }

            $sources[$id] = $source;
        }

        $sourceRows = array_map(function (array $source): ChannelMixRowData {
            $row = $source['row'];
            $type = BookingSourceType::from((string) $row['source_type']);
            $name = (string) $row['source_name'];

            return new ChannelMixRowData(
                key: (string) $row['source_id'],
                name: $name,
                type: $type,
                badge: new BookingSourceBadgeData(
                    $name,
                    $type,
                    $row['badge_icon'] === null ? null : BookingSourceIcon::from((string) $row['badge_icon']),
                    $row['badge_letters'] === null ? null : (string) $row['badge_letters'],
                    SwatchColor::from((string) $row['badge_color']),
                ),
                nights: $source['nights'],
                share: 0,
                amount: (string) $source['amount']->toScale(2, RoundingMode::HalfUp),
                companies: $this->companies($source['companies']),
            );
        }, array_values($sources));

        $typeRows = [];

        foreach ($sources as $source) {
            $type = BookingSourceType::from((string) $source['row']['source_type']);
            $entry = $typeRows[$type->value] ?? ['nights' => 0, 'amount' => BigDecimal::zero(), 'companies' => []];
            $entry['nights'] += $source['nights'];
            $entry['amount'] = $entry['amount']->plus($source['amount']);

            foreach ($source['companies'] as $companyId => $company) {
                $entry['companies'][$companyId] = [
                    'name' => $company['name'],
                    'nights' => ($entry['companies'][$companyId]['nights'] ?? 0) + $company['nights'],
                ];
            }

            $typeRows[$type->value] = $entry;
        }

        $typeRows = array_map(fn (string $value, array $entry): ChannelMixRowData => new ChannelMixRowData(
            key: $value,
            name: BookingSourceType::from($value)->label(),
            type: BookingSourceType::from($value),
            badge: null,
            nights: $entry['nights'],
            share: 0,
            amount: (string) $entry['amount']->toScale(2, RoundingMode::HalfUp),
            companies: $this->companies($entry['companies']),
        ), array_keys($typeRows), array_values($typeRows));

        $nights = array_sum(array_map(fn (ChannelMixRowData $row): int => $row->nights, $sourceRows));
        $amount = array_reduce($sourceRows, fn (BigDecimal $sum, ChannelMixRowData $row): BigDecimal => $sum->plus($row->amount), BigDecimal::zero());

        return new PerformanceChannelMixData(
            nights: $nights,
            amount: (string) $amount->toScale(2, RoundingMode::HalfUp),
            bySource: $this->withShares($sourceRows, fn (ChannelMixRowData $row): array => [$row->name]),
            byType: $this->withShares($typeRows, fn (ChannelMixRowData $row): array => [$row->type->listOrder()]),
        );
    }

    /**
     * Largest first, then by the tie breaker, with whole-percent shares that add up to 100.
     *
     * @param  list<ChannelMixRowData>  $rows
     * @param  callable(ChannelMixRowData): array<int, int|string>  $tieBreak
     * @return list<ChannelMixRowData>
     */
    private function withShares(array $rows, callable $tieBreak): array
    {
        usort($rows, fn (ChannelMixRowData $a, ChannelMixRowData $b): int => [$b->nights, ...$tieBreak($a)] <=> [$a->nights, ...$tieBreak($b)]);
        $shares = $this->shares->percentages(array_map(fn (ChannelMixRowData $row): int => $row->nights, $rows));

        return array_map(function (ChannelMixRowData $row, int $share): ChannelMixRowData {
            $row->share = $share;

            return $row;
        }, $rows, $shares);
    }

    /**
     * @param  array<int, array{name: string, nights: int}>  $companies  By company id, so two companies with one name stay apart.
     * @return list<ChannelMixCompanyData>
     */
    private function companies(array $companies): array
    {
        uasort($companies, fn (array $a, array $b): int => [$b['nights'], $a['name']] <=> [$a['nights'], $b['name']]);

        return array_values(array_map(fn (array $company): ChannelMixCompanyData => new ChannelMixCompanyData($company['name'], $company['nights']), $companies));
    }

    /**
     * @return list<string>
     */
    private function liveStatuses(): array
    {
        return array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory());
    }
}
