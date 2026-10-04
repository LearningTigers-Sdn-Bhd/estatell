<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * One company in the aging report, or the totals row (`companyId` null). Buckets are decimal strings with two places.
 * `credit` is the company's unallocated payments as a negative amount, and `total` is the buckets plus the credit.
 */
class AgingRowData extends Data
{
    public function __construct(
        public ?int $companyId,
        public string $companyName,
        public string $current,
        public string $days1to30,
        public string $days31to60,
        public string $days61to90,
        public string $over90,
        public string $credit,
        public string $total,
    ) {}
}
