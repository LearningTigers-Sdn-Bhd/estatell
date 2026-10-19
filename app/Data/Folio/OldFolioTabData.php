<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One tab of the old folio page: an Open folio of the reservation with its old folio number. Before the first save
 * `rows` are the drafts (room nights drafted from the unit nights). After it `lines` are the saved lines and `total`
 * their sum.
 */
class OldFolioTabData extends Data
{
    /**
     * @param  list<OldFolioRowData>  $rows
     * @param  list<OldFolioLineData>  $lines
     */
    public function __construct(
        public int $folioId,
        public string $label,
        public ?string $legacyNumber,
        public array $rows,
        public array $lines,
        public ?string $total,
    ) {}
}
