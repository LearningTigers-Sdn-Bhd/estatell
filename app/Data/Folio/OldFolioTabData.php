<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One tab of the old folio page: an Open folio of the reservation with its old folio number, or a unit that has no
 * folio yet (its `folioId` is `-{unit id}` and `SaveOldFolios` creates the folio on save). `rows` are the saved lines
 * (with their `lineId`) followed by the room nights drafted from the unit nights that were never saved.
 * `openingBalance` is the live opening balance of the folio, or null: saving a line here replaces it.
 */
class OldFolioTabData extends Data
{
    /**
     * @param  list<OldFolioRowData>  $rows
     */
    public function __construct(
        public int $folioId,
        public string $label,
        public ?string $legacyNumber,
        public array $rows,
        public ?string $openingBalance,
    ) {}
}
