<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The note above a unit folio that holds taxes whose room charge is on a folio a company pays: `payer` pays the room,
 * not `taxes`. `companyId` is set only for a user who may change the company, so the page links to the switch that
 * makes the company pay them too. `moveLineIds` are the taxes that can move back under their room line on `moveToFolioId`
 * (named `moveTo`), the first folio a company pays, for the note's Move button (Move charges with them ticked). See
 * docs/13-folio/05-folio-page.md#collected-taxes.
 */
class CollectedTaxNoteData extends Data
{
    /**
     * @param  list<string>  $taxes
     * @param  list<int>  $moveLineIds
     */
    public function __construct(
        public string $payer,
        public array $taxes,
        public ?int $companyId = null,
        public array $moveLineIds = [],
        public ?int $moveToFolioId = null,
        public ?string $moveTo = null,
    ) {}
}
