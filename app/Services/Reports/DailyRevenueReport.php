<?php

namespace App\Services\Reports;

use App\Data\Reports\DailyRevenueReportRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Enums\ChargeBucket;
use App\Enums\ReportGroup;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\Tax;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Daily report › Revenue: one row for each date, or for each month with `group=month`, read live from
 * `DailyRevenueQuery` by posting date. Charges go in Accommodation (category Room, without day use), Day use, Fees
 * (category Fee) or Extras (every other charge); tax lines go in one column for each tax of the property, keyed
 * `tax:{taxes.code}`; discounts and adjustments go in Adjustments. Total is the sum of the columns. Bookings is the
 * number of distinct reservations with a Charge or Adjustment line, and the total row counts distinct reservations over
 * the rows, not the sum. Each row carries its `from` and `to` (a month is cut to the dates asked for) for the
 * drill-down to Charges. See docs/22-reports/02-guest-list-and-daily-report.md#revenue.
 */
class DailyRevenueReport implements HasTotals, Report
{
    public function __construct(private DailyRevenueQuery $revenue) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<DailyRevenueReportRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $group = $filters->group ?? ReportGroup::Day;
        $byMonth = $group === ReportGroup::Month;
        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        $taxCodes = Tax::query()->where('property_id', $property->id)->pluck('code', 'transaction_code_id')->all();
        $zeroTaxes = array_fill_keys(array_values($taxCodes), BigDecimal::zero());
        $reservations = $this->revenue->bookingIdsBetween($property, $from, $to, $byMonth);

        /** @var array<string, array{accommodation: BigDecimal, day_use: BigDecimal, fees: BigDecimal, extras: BigDecimal, taxes: array<string, BigDecimal>, adjustments: BigDecimal}> $periods */
        $periods = [];

        foreach ($this->revenue->bucketLinesBetween($property, $from, $to) as $line) {
            $period = CarbonImmutable::parse($line['date'])->format($byMonth ? 'Y-m' : 'Y-m-d');
            $bucket = $periods[$period] ?? ['accommodation' => BigDecimal::zero(), 'day_use' => BigDecimal::zero(), 'fees' => BigDecimal::zero(), 'extras' => BigDecimal::zero(), 'taxes' => $zeroTaxes, 'adjustments' => BigDecimal::zero()];
            $amount = BigDecimal::of($line['amount']);

            if ($line['kind'] === TransactionKind::Tax->value) {
                $code = $taxCodes[$line['transactionCodeId']] ?? null;

                if ($code !== null) {
                    $bucket['taxes'][$code] = $bucket['taxes'][$code]->plus($amount);
                }
            } elseif (($chargeBucket = ChargeBucket::forLine(TransactionKind::from($line['kind']), $line['category'], $line['systemKey'])) !== null) {
                $column = match ($chargeBucket) {
                    ChargeBucket::Accommodation => 'accommodation',
                    ChargeBucket::DayUse => 'day_use',
                    ChargeBucket::Fees => 'fees',
                    ChargeBucket::Extras => 'extras',
                    ChargeBucket::Adjustments => 'adjustments',
                };
                $bucket[$column] = $bucket[$column]->plus($amount);
            }

            $periods[$period] = $bucket;
        }

        ksort($periods);

        if ($rowKeys !== null) {
            $periods = array_intersect_key($periods, array_flip(array_map('strval', $rowKeys)));
        }

        $rows = [];

        foreach ($periods as $period => $bucket) {
            $period = (string) $period;
            $ids = $reservations[$period] ?? [];
            $total = array_reduce($bucket['taxes'], fn (BigDecimal $sum, BigDecimal $tax): BigDecimal => $sum->plus($tax), BigDecimal::zero())
                ->plus($bucket['accommodation'])->plus($bucket['day_use'])->plus($bucket['fees'])->plus($bucket['extras'])->plus($bucket['adjustments']);

            $rows[] = new DailyRevenueReportRow(
                key: $period,
                group: $group,
                period: $period,
                bookings: count($ids),
                accommodation: $this->format($bucket['accommodation']),
                day_use: $this->format($bucket['day_use']),
                fees: $this->format($bucket['fees']),
                extras: $this->format($bucket['extras']),
                taxes: array_map($this->format(...), $bucket['taxes']),
                adjustments: $this->format($bucket['adjustments']),
                total: $this->format($total),
                from: $byMonth ? max($from->toDateString(), CarbonImmutable::parse("{$period}-01")->toDateString()) : $period,
                to: $byMonth ? min($to->toDateString(), CarbonImmutable::parse("{$period}-01")->endOfMonth()->toDateString()) : $period,
                reservationIds: $ids,
            );
        }

        return $rows;
    }

    /**
     * The sums of the rows. Bookings counts distinct reservations over the rows.
     *
     * @param  list<DailyRevenueReportRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $sum = fn (callable $amount): string => $this->format(array_reduce($rows, fn (BigDecimal $sum, DailyRevenueReportRow $row): BigDecimal => $sum->plus($amount($row)), BigDecimal::zero()));
        $taxCodes = array_values(array_unique(array_merge([], ...array_map(fn (DailyRevenueReportRow $row): array => array_keys($row->taxes), $rows))));
        $taxes = [];

        foreach ($taxCodes as $code) {
            $taxes["tax:{$code}"] = $sum(fn (DailyRevenueReportRow $row): string => $row->taxes[$code] ?? '0');
        }

        return new ReportTotals([
            'period' => null,
            'bookings' => count(array_unique(array_merge([], ...array_map(fn (DailyRevenueReportRow $row): array => $row->reservationIds, $rows)))),
            'accommodation' => $sum(fn (DailyRevenueReportRow $row): string => $row->accommodation),
            'day_use' => $sum(fn (DailyRevenueReportRow $row): string => $row->day_use),
            'fees' => $sum(fn (DailyRevenueReportRow $row): string => $row->fees),
            'extras' => $sum(fn (DailyRevenueReportRow $row): string => $row->extras),
            ...$taxes,
            'adjustments' => $sum(fn (DailyRevenueReportRow $row): string => $row->adjustments),
            'total' => $sum(fn (DailyRevenueReportRow $row): string => $row->total),
        ]);
    }

    private function format(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }
}
