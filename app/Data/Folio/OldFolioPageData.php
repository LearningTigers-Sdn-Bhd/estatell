<?php

namespace App\Data\Folio;

use App\Data\Property\CurrencyData;
use Spatie\LaravelData\Data;

/**
 * The old folio page: the Open folios of the reservation as tabs, the items a row picks from and the dates. `goLiveDate`
 * is the first business date: rows are dated before it. `readOnly` is true once the reservation has migrated lines (the
 * first save is the only one for now). `canAddMaster` is false when the reservation has a Master folio.
 * `rows` of a tab are the saved lines when read only.
 */
class OldFolioPageData extends Data
{
    /**
     * @param  list<OldFolioTabData>  $tabs
     * @param  list<OldFolioOptionData>  $items
     */
    public function __construct(
        public int $reservationId,
        public string $reference,
        public string $guest,
        public string $title,
        public string $goLiveDate,
        public string $businessDate,
        public ?CurrencyData $currency,
        public bool $readOnly,
        public bool $canAddMaster,
        public bool $canViewCompany,
        public array $tabs,
        public array $items,
        public int $maxRows,
        public string $cancelUrl,
    ) {}
}
