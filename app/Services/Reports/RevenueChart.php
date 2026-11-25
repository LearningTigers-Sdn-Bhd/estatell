<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportRow;
use App\Data\Reports\RevenueChartCodeData;
use App\Data\Reports\RevenueChartData;
use App\Data\Reports\RevenueChartPeriodData;
use App\Data\Reports\RevenueRow;
use App\Enums\ReportGroup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * The charts of Revenue by transaction code, from every row of the range (not one page): the codes ranked by amount,
 * the highest first, with the codes past `TOP_CODES` summed as "Other codes", and the total of every day or month of
 * the range, 0.00 where there were no lines. See docs/22-reports/06-report-charts.md.
 */
class RevenueChart
{
    /** The codes the ranking names; the rest are one "Other codes" bar. */
    public const int TOP_CODES = 8;

    /**
     * @param  list<ReportRow>  $rows
     */
    public function forRows(array $rows, ReportFilters $filters): RevenueChartData
    {
        $rows = array_filter($rows, fn (ReportRow $row): bool => $row instanceof RevenueRow);
        $group = $filters->group ?? ReportGroup::Day;

        /** @var array<string, array{description: string, amount: BigDecimal}> $codes */
        $codes = [];
        /** @var array<string, BigDecimal> $periods */
        $periods = [];

        $step = $group === ReportGroup::Month ? '1 month' : '1 day';
        $format = $group === ReportGroup::Month ? 'Y-m' : 'Y-m-d';
        $from = CarbonImmutable::parse($filters->from);

        foreach (CarbonPeriod::create($group === ReportGroup::Month ? $from->startOfMonth() : $from, $step, $filters->to) as $date) {
            $periods[$date->format($format)] = BigDecimal::zero();
        }

        foreach ($rows as $row) {
            $code = $codes[$row->code] ?? ['description' => $row->description, 'amount' => BigDecimal::zero()];
            $codes[$row->code] = [...$code, 'amount' => $code['amount']->plus($row->amount)];
            $periods[$row->period] = ($periods[$row->period] ?? BigDecimal::zero())->plus($row->amount);
        }

        uksort($codes, fn (int|string $a, int|string $b): int => $codes[$b]['amount']->compareTo($codes[$a]['amount']) ?: strcmp((string) $a, (string) $b));

        $ranked = [];
        $other = BigDecimal::zero();
        $named = count($codes) > self::TOP_CODES + 1 ? self::TOP_CODES : count($codes);

        foreach (array_keys($codes) as $index => $code) {
            if ($index < $named) {
                $ranked[] = new RevenueChartCodeData((string) $code, $codes[$code]['description'], $this->money($codes[$code]['amount']));
            } else {
                $other = $other->plus($codes[$code]['amount']);
            }
        }

        if ($named < count($codes)) {
            $ranked[] = new RevenueChartCodeData('', __('Other codes'), $this->money($other));
        }

        ksort($periods);

        return new RevenueChartData(
            group: $group,
            codes: $ranked,
            periods: array_map(fn (string $period): RevenueChartPeriodData => new RevenueChartPeriodData($period, $this->money($periods[$period])), array_keys($periods)),
        );
    }

    private function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}
