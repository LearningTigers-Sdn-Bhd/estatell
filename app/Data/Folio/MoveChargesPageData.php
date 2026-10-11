<?php

namespace App\Data\Folio;

use App\Enums\Currency;
use Spatie\LaravelData\Data;

/**
 * The props of the Move charges page. `reference` and `reservationId` name the reservation in the breadcrumbs. `folios` are the Open folios with their movable charges, `targetId` the folio the
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
        public int $reservationId,
        public string $reference,
        public string $title,
        public ?Currency $currency,
        public array $folios,
        public int $targetId,
        public array $lineIds,
        public int $maxCharges,
        public MovePreviewData $preview,
        public string $cancelUrl,
    ) {}
}
