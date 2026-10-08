<?php

namespace App\Data\Reports;

use App\Enums\ReportGroup;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Revenue report: one transaction code on one date, or in one month. The property names are the column
 * keys of `ReportKey::Revenue`. `period` is `Y-m-d` for a day and `Y-m` for a month. `key` is the period and the
 * transaction code id (`2026-10-07:12`). `from` and `to` are the dates the row covers, cut to the report range: they
 * open the Folio ledger for the row.
 */
class RevenueRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public ReportGroup $group,
        public string $period,
        public string $code,
        public string $description,
        public int $lines,
        public string $amount,
        public string $from,
        public string $to,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'period' => CarbonImmutable::parse($this->period.($this->group === ReportGroup::Month ? '-01' : ''))
                ->format($this->group === ReportGroup::Month ? 'M Y' : 'j M Y'),
            'code' => $this->code,
            'description' => $this->description,
            'lines' => (string) $this->lines,
            'amount' => number_format((float) $this->amount, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'period' => $this->period,
            'code' => $this->code,
            'description' => $this->description,
            'lines' => $this->lines,
            'amount' => round((float) $this->amount, 2),
        ];
    }
}
