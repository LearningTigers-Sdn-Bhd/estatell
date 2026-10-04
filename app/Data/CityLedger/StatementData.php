<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * The statement of account of one company between two dates. The aging row is as at the end date, by the current
 * allocations, so for a past date it is an estimate.
 */
class StatementData extends Data
{
    /**
     * @param  list<StatementLineData>  $lines
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $openingBalance,
        public array $lines,
        public string $closingBalance,
        public AgingRowData $aging,
    ) {}
}
