<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the row menu Dialogs of a posted charge need: the amount to split (unit amount times quantity) and its live tax
 * lines. Edit line also needs the description, the unit amount, the quantity, the ids of the live taxes and, for a live
 * room line, the night behind it.
 */
class ManualLineData extends Data
{
    /**
     * @param  list<ManualLineTaxData>  $taxes
     * @param  list<int>  $taxIds
     */
    public function __construct(
        public int $lineId,
        public string $total,
        public array $taxes,
        public string $description = '',
        public string $unitAmount = '0.00',
        public int $quantity = 1,
        public array $taxIds = [],
        public ?ManualLineNightData $night = null,
    ) {}
}
