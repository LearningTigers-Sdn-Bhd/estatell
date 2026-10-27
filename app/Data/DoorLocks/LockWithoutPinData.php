<?php

namespace App\Data\DoorLocks;

use Spatie\LaravelData\Data;

/**
 * A lock on a checked-in unit's room that has no live PIN. `reason` says why when we know, for example that the stay already ended.
 */
class LockWithoutPinData extends Data
{
    public function __construct(
        public string $name,
        public ?string $reason,
    ) {}
}
