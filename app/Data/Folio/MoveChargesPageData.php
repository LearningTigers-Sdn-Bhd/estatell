<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The props of the Move charges page. `folios` are the Open folios with their movable charges, `targetId` the folio the
 * To column starts on and `lineIds` the charges ticked at the start. `preview` is the first preview for that start;
 * the page asks for a new one after each change. `cancelUrl` is the folio tab on the target.
 */
class MoveChargesPageData extends Data
{
    /**
     * @param  list<MoveFolioData>  $folios
     * @param  list<int>  $lineIds
     */
    public function __construct(
        public string $title,
        public string $currency,
        public array $folios,
        public int $targetId,
        public array $lineIds,
        public int $maxCharges,
        public MovePreviewData $preview,
        public string $cancelUrl,
    ) {}
}
