<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * One line of the Overview impact table: the rooms a kind of change touched and the room nights and revenue it added
 * (negative when it took them away). `kind` is null on the Net line. `arr` is revenue over room nights.
 */
class RoomsOnBooksImpactData extends Data
{
    public function __construct(
        public ?string $kind,
        public string $label,
        public int $rooms,
        public int $nights,
        public string $revenue,
        public string $arr,
    ) {}
}
