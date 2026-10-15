<?php

namespace App\Data\Guests;

use Spatie\LaravelData\Data;

class GuestBulkResultData extends Data
{
    /**
     * @param  list<int>  $changedIds
     * @param  list<int>  $unchangedIds
     * @param  list<int>  $skippedIds
     */
    public function __construct(
        public array $changedIds,
        public array $unchangedIds,
        public array $skippedIds,
    ) {}
}
