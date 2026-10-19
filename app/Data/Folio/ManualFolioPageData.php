<?php

namespace App\Data\Folio;

use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Payments\PaymentMethodOptionData;
use App\Data\Property\CurrencyData;
use App\Data\Reservations\ChargeItemOptionData;
use Spatie\LaravelData\Data;

/**
 * The manual folio page: the folio being managed, the lists its rows pick from, the first (empty) preview and the
 * Posted lines of the folio (read only). `reason` is what the reason dialog sent, null when the page was opened by
 * its address, which makes the page ask for it. `itemTaxIds` gives each charge item's own tax ids by item id, so a
 * row starts with them. `earliestDate` and `businessDate` bound a row's service date. `batchKey` is the form's UUID.
 * See docs/13-folio/08-manual-folio.md.
 */
class ManualFolioPageData extends Data
{
    /**
     * @param  list<ChargeItemOptionData>  $chargeItems
     * @param  array<int, list<int>>  $itemTaxIds
     * @param  list<ChargeItemTaxData>  $taxes
     * @param  list<PaymentMethodOptionData>  $paymentMethods
     * @param  list<ManualPaymentOptionData>  $payments
     * @param  list<ManualFolioOptionData>  $folios
     * @param  list<FolioOptionData>  $units
     */
    public function __construct(
        public int $reservationId,
        public string $reference,
        public string $title,
        public int $folioId,
        public string $folioLabel,
        public ?string $reason,
        public ?CurrencyData $currency,
        public string $businessDate,
        public string $earliestDate,
        public array $chargeItems,
        public array $itemTaxIds,
        public array $taxes,
        public array $paymentMethods,
        public array $payments,
        public array $folios,
        public array $units,
        public ManualPreviewData $preview,
        public ?FolioData $posted,
        public string $batchKey,
        public int $maxRows,
        public string $cancelUrl,
    ) {}
}
