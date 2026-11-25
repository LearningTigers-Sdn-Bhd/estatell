<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * One item added to an order. Repeat an option id to take it more than once. `unitPrice` is only for an item priced at the
 * register. `coverWithEntitlement` bills the dish to the guest's included meal (the order needs a room). `courseNumber` and `seatNumber` place the line for coursing and seat splits.
 */
class PosOrderLineInputData extends Data
{
    /**
     * @param  list<int>  $modifierOptionIds
     */
    public function __construct(
        public int $variantId,
        public int $quantity = 1,
        public array $modifierOptionIds = [],
        public ?string $note = null,
        public ?string $unitPrice = null,
        public bool $coverWithEntitlement = false,
        public int $courseNumber = 1,
        public ?int $seatNumber = null,
    ) {}
}
