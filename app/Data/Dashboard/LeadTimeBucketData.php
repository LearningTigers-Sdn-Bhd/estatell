<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * One lead time bucket: Same day, 1–7 days, 8–30 days or 31+ days.
 */
class LeadTimeBucketData extends Data
{
    /**
     * @param  string  $key  `same-day`, `1-7`, `8-30` or `31-plus`.
     * @param  int  $share  Whole percent. The four buckets add up to 100.
     */
    public function __construct(
        public string $key,
        public int $units,
        public int $share,
    ) {}
}
