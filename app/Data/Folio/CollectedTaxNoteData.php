<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The note above a unit folio that holds taxes whose room charge is on a folio a company pays: `payer` pays the room,
 * not `taxes`. `companyId` is set only for a user who may change the company, so the page links to the switch that
 * makes the company pay them too. See docs/13-folio/05-folio-page.md#collected-taxes.
 */
class CollectedTaxNoteData extends Data
{
    /**
     * @param  list<string>  $taxes
     */
    public function __construct(
        public string $payer,
        public array $taxes,
        public ?int $companyId = null,
    ) {}
}
