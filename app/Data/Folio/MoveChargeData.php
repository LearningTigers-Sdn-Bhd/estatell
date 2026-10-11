<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One posted charge on the Move charges page, with its live tax and discount children. `total` is the charge plus
 * those children, the amount that leaves the source and lands on the target. Only charges that `TransferLines` accepts are
 * listed (no child, reversal, reversed line or payment line). `isRoom` is true for a Room category charge (the Room quick
 * select). `unit` is the label of the room that caused the charge.
 */
class MoveChargeData extends Data
{
    /**
     * @param  list<MoveChildData>  $children
     */
    public function __construct(
        public int $lineId,
        public string $date,
        public string $description,
        public ?string $unit,
        public string $amount,
        public string $total,
        public bool $isRoom,
        public array $children,
    ) {}
}
