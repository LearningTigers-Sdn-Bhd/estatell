<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The values the Edit line Dialog sends for a charge: its description, the unit amount, the quantity and the ids of the
 * taxes it carries (the whole list, not a change).
 */
class EditLineData extends Data
{
    /**
     * @param  list<int>  $taxIds
     */
    public function __construct(
        public string $description,
        public string $amount,
        public int $quantity,
        public array $taxIds,
    ) {}
}
