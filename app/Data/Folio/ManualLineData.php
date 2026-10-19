<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the row menu Dialogs of a posted charge need: the amount to split (unit amount times quantity) and its live tax
 * lines.
 */
class ManualLineData extends Data
{
    /**
     * @param  list<ManualLineTaxData>  $taxes
     */
    public function __construct(
        public int $lineId,
        public string $total,
        public array $taxes,
    ) {}
}
