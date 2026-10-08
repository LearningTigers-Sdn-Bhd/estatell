<?php

namespace App\Services\Reports;

use App\Data\Reports\NightAuditHistoryRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\AuditStatus;
use App\Models\DailyStatistic;
use App\Models\NightAudit;
use App\Models\Property;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row for each night audit whose business date is in the range, with its status, who ran it, the exceptions and
 * the date's occupancy (from the `daily_statistics` snapshot) and room revenue (from `DailyRevenueQuery`, like the
 * Occupancy report). Room revenue shows only for a Completed audit: the date of any other audit is not closed. A
 * Completed audit links to its packet PDF. The total row sums the two exception counts. See docs/22-reports/01-core-reports.md.
 */
class NightAuditHistoryReport implements HasTotals, Report
{
    public function __construct(private DailyRevenueQuery $revenue) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Business dates, `Y-m-d`.
     * @return list<NightAuditHistoryRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $audits = NightAudit::query()
            ->where('property_id', $property->id)
            ->whereHas('businessDate', function (Builder $dates) use ($filters, $rowKeys): void {
                $dates->whereBetween('date', [$filters->from, $filters->to])
                    ->when($rowKeys !== null, fn (Builder $query) => $query->whereIn('date', array_map('strval', $rowKeys)));
            })
            ->with(['businessDate', 'runBy'])
            ->withCount(['exceptions', 'exceptions as open_exceptions_count' => fn (Builder $query) => $query->whereNull('resolved_at')])
            ->get();

        if ($audits->isEmpty()) {
            return [];
        }

        $occupancy = DailyStatistic::query()
            ->where('property_id', $property->id)
            ->whereBetween('business_date', [$filters->from, $filters->to])
            ->pluck('occupancy', 'business_date')
            ->mapWithKeys(fn (string $value, string $date): array => [substr($date, 0, 10) => $value]);
        $revenue = $this->revenue->roomRevenueBetween($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to));
        $zero = Money::zero($property->loadMissing('settings')->settings->currency->value);
        $timeZone = $property->settings->time_zone ?? config('app.timezone');

        return $audits
            ->sortBy(fn (NightAudit $audit): string => $audit->businessDate->date->toDateString())
            ->map(function (NightAudit $audit) use ($occupancy, $revenue, $zero, $timeZone): NightAuditHistoryRow {
                $date = $audit->businessDate->date->toDateString();

                return NightAuditHistoryRow::fromAudit(
                    $audit,
                    $timeZone,
                    $occupancy[$date] ?? null,
                    $audit->status === AuditStatus::Completed ? ($revenue[$date] ?? $zero) : null,
                );
            })
            ->values()
            ->all();
    }

    /**
     * The sums of the open exceptions and of all exceptions.
     *
     * @param  list<NightAuditHistoryRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'date' => null,
            'status' => null,
            'trigger' => null,
            'run_by' => null,
            'started' => null,
            'started_date' => null,
            'started_time' => null,
            'completed' => null,
            'completed_date' => null,
            'completed_time' => null,
            'forced' => null,
            'exceptions' => null,
            'exceptions_open' => array_sum(array_map(fn (NightAuditHistoryRow $row): int => $row->exceptions_open, $rows)),
            'exceptions_total' => array_sum(array_map(fn (NightAuditHistoryRow $row): int => $row->exceptions_total, $rows)),
            'occupancy' => null,
            'revenue' => null,
            'packet' => null,
        ]);
    }
}
