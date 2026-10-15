<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * What the Waiting for approval tab adds to a property row: when the request was sent and how many warnings it has.
 */
class AdminWaitingPropertyData extends Data
{
    public function __construct(
        public string $sentOn,
        public int $warningsCount,
    ) {}
}
