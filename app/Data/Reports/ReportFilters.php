<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The checked filters of a report: the first and last date (`Y-m-d`, both included).
 */
class ReportFilters extends Data
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    /**
     * The number of days in the range, counting both ends.
     */
    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }
}
