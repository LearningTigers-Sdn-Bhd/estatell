<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * A room, or a bed in a dorm room, that is free on every night of a stay. `label` is "101" for a room and
 * "D1 · Bed 2" for a bed. `reason` is set only on a walk-in list for a unit that is not ready ("In house" or "Dirty"),
 * which the picker shows disabled.
 */
class FreeUnitData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public ?string $reason = null,
    ) {}
}
