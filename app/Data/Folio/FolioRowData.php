<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One row of the folio page. `state` is posted or scheduled. `level` is 0 for a charge or a payment and 1 or 2 for
 * its discount and tax children. `tags` are labels such as Scheduled, Overridden, Reversal and Reversed.
 */
class FolioRowData extends Data
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $key,
        public string $state,
        public string $date,
        public string $description,
        public int $level,
        public ?string $unit,
        public string $amount,
        public array $tags,
    ) {}
}
