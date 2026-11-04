<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One charged per-guest add-on that Change guests corrects, as the Dialog lists it with a tick box. `key` is sent back
 * in `skip_add_ons` when staff untick it. `canApply` is false when the correction reverses a line and the user may
 * not reverse charges; it is then left for someone who can.
 */
class UnitPartyAddOnData extends Data
{
    public function __construct(
        public string $key,
        public string $name,
        public string $summary,
        public string $reverseAmount,
        public string $chargeAmount,
        public bool $needsReversal,
        public bool $canApply,
    ) {}
}
