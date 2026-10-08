<?php

namespace App\Data\Reports;

use App\Enums\ReportGroup;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Tax report: one tax on one date, or in one month. The property names are the column keys of
 * `ReportKey::Tax`. `period` is `Y-m-d` for a day and `Y-m` for a month. `key` is the period and the transaction code
 * id of the tax (`2026-10-07:12`). `lines` and `amount` include reversals.
 */
class TaxRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public ReportGroup $group,
        public string $period,
        public string $tax,
        public ?string $registration,
        public int $lines,
        public string $amount,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'period' => CarbonImmutable::parse($this->period.($this->group === ReportGroup::Month ? '-01' : ''))
                ->format($this->group === ReportGroup::Month ? 'M Y' : 'j M Y'),
            'tax' => $this->tax,
            'registration' => $this->registration ?? '',
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
            'tax' => $this->tax,
            'registration' => $this->registration ?? '',
            'lines' => $this->lines,
            'amount' => round((float) $this->amount, 2),
        ];
    }
}
