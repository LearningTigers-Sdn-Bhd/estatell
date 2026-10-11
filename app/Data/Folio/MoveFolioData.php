<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One Open folio on the Move charges page: its name (`FolioStatement::optionLabel()`), its balance and its posted
 * charges that can move. A folio is a source in the From column or the target in the To column; the browser picks.
 */
class MoveFolioData extends Data
{
    /**
     * @param  list<MoveChargeData>  $charges
     */
    public function __construct(
        public int $id,
        public string $label,
        public string $kind,
        public string $balance,
        public array $charges,
    ) {}
}
