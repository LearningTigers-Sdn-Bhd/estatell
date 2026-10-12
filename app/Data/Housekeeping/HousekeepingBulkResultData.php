<?php

namespace App\Data\Housekeeping;

use App\Enums\HousekeepingRefusal;
use Spatie\LaravelData\Data;

/**
 * What a bulk update did: how many rooms changed or were already as wanted, and the rooms it skipped with the reason.
 */
class HousekeepingBulkResultData extends Data
{
    /**
     * @param  list<array{number: string, refusal: HousekeepingRefusal}>  $skipped
     */
    public function __construct(
        public int $done,
        public array $skipped,
    ) {}
}
