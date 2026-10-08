<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Data\Reports\RevenueRow;
use App\Enums\ReportGroup;
use App\Models\Property;
use App\Models\TransactionCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Revenue by transaction code, live from `DailyRevenueQuery` for every date in the range, open or closed: one row for
 * each date and code, or for each month and code with `group=month`. Payments are not revenue and are not in the
 * query. A month holds only the dates inside the range. Nothing is read from `daily_revenue`. See
 * docs/22-reports/01-core-reports.md.
 */
class RevenueReport implements HasTotals, Report
{
    public function __construct(private DailyRevenueQuery $revenue) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<RevenueRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $group = $filters->group ?? ReportGroup::Day;
        $lines = $this->revenue->between($property, CarbonImmutable::parse($filters->from), CarbonImmutable::parse($filters->to));
        $codes = TransactionCode::query()
            ->where('property_id', $property->id)
            ->whereIn('id', $lines->pluck('transactionCodeId')->unique()->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        /** @var array<string, array{period: string, codeId: int, lines: int, amount: BigDecimal}> $buckets */
        $buckets = [];

        foreach ($lines as $line) {
            $period = $line->date->format($group === ReportGroup::Month ? 'Y-m' : 'Y-m-d');
            $key = "{$period}:{$line->transactionCodeId}";
            $bucket = $buckets[$key] ?? ['period' => $period, 'codeId' => $line->transactionCodeId, 'lines' => 0, 'amount' => BigDecimal::zero()];
            $buckets[$key] = [
                ...$bucket,
                'lines' => $bucket['lines'] + $line->lineCount,
                'amount' => $bucket['amount']->plus($line->amount->getAmount()),
            ];
        }

        if ($rowKeys !== null) {
            $buckets = array_intersect_key($buckets, array_flip(array_map('strval', $rowKeys)));
        }

        $rows = [];

        foreach ($buckets as $key => $bucket) {
            $code = $codes->get($bucket['codeId']);
            $periodDate = CarbonImmutable::parse($bucket['period'].($group === ReportGroup::Month ? '-01' : ''));
            $rows[] = new RevenueRow(
                key: $key,
                group: $group,
                period: $bucket['period'],
                code: $code?->code ?? '',
                description: $code?->name ?? '',
                lines: $bucket['lines'],
                amount: (string) $bucket['amount']->toScale(2, RoundingMode::HalfUp),
                from: $group === ReportGroup::Month ? max($periodDate->startOfMonth()->toDateString(), $filters->from) : $bucket['period'],
                to: $group === ReportGroup::Month ? min($periodDate->endOfMonth()->toDateString(), $filters->to) : $bucket['period'],
            );
        }

        return $rows;
    }

    /**
     * The number of lines and the amount of the rows.
     *
     * @param  list<RevenueRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $amount = array_reduce($rows, fn (BigDecimal $sum, RevenueRow $row): BigDecimal => $sum->plus($row->amount), BigDecimal::zero());

        return new ReportTotals([
            'period' => null,
            'code' => null,
            'description' => null,
            'lines' => array_sum(array_map(fn (RevenueRow $row): int => $row->lines, $rows)),
            'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp),
        ]);
    }
}
