<?php

namespace App\Data\Reports;

use App\Enums\ReportGroup;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden as HiddenFromTypeScript;

/**
 * One row of Daily report › Revenue: one date, or one month. The property names are the column keys of
 * `ReportKey::DailyRevenue`; a tax column `tax:{code}` reads `taxes[code]`. `period` is `Y-m-d` for a day and `Y-m` for
 * a month. `key` is the period. `reservationIds` are the reservations behind `bookings`, kept so the total row can
 * count distinct reservations; they are not sent to the page. `from` and `to` are the first and last date (`Y-m-d`) of
 * the row inside the dates asked for, so the page can link a cell to Daily report › Charges for exactly those lines.
 */
class DailyRevenueReportRow extends Data implements ReportRow
{
    /**
     * @param  array<string, string>  $taxes  The tax amount for each tax code.
     * @param  list<int>  $reservationIds
     */
    public function __construct(
        public string $key,
        public ReportGroup $group,
        public string $period,
        public int $bookings,
        public string $accommodation,
        public string $day_use,
        public string $fees,
        public string $extras,
        public array $taxes,
        public string $adjustments,
        public string $total,
        public string $from,
        public string $to,
        #[Hidden, HiddenFromTypeScript]
        public array $reservationIds = [],
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'period' => CarbonImmutable::parse($this->period.($this->group === ReportGroup::Month ? '-01' : ''))
                ->format($this->group === ReportGroup::Month ? 'M Y' : 'j M Y'),
            'bookings' => (string) $this->bookings,
            'accommodation' => number_format((float) $this->accommodation, 2),
            'day_use' => number_format((float) $this->day_use, 2),
            'fees' => number_format((float) $this->fees, 2),
            'extras' => number_format((float) $this->extras, 2),
            ...$this->taxCells(),
            'adjustments' => number_format((float) $this->adjustments, 2),
            'total' => number_format((float) $this->total, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'period' => $this->period,
            'bookings' => $this->bookings,
            'accommodation' => round((float) $this->accommodation, 2),
            'day_use' => round((float) $this->day_use, 2),
            'fees' => round((float) $this->fees, 2),
            'extras' => round((float) $this->extras, 2),
            ...array_combine(
                array_map(fn (string $code): string => "tax:{$code}", array_keys($this->taxes)),
                array_map(fn (string $amount): float => round((float) $amount, 2), array_values($this->taxes)),
            ),
            'adjustments' => round((float) $this->adjustments, 2),
            'total' => round((float) $this->total, 2),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function taxCells(): array
    {
        $cells = [];

        foreach ($this->taxes as $code => $amount) {
            $cells["tax:{$code}"] = number_format((float) $amount, 2);
        }

        return $cells;
    }
}
