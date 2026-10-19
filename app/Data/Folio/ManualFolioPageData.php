<?php

namespace App\Data\Folio;

use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Payments\PaymentMethodOptionData;
use App\Data\Property\CurrencyData;
use App\Data\Reservations\ChargeItemOptionData;
use Spatie\LaravelData\Data;

/**
 * The manual folio page: the folio being managed, the lists its rows pick from, the first (empty) preview and the
 * Posted lines of the folio with their row menu (`lines` holds what the menu's Dialogs need). `rail` is the folio rail: the Open folios of the reservation with their
 * balance. `reason` is what the reason dialog sent (`reasonOptions` are its choices), null when the page was opened
 * by its address, which makes the page ask for it. `itemTaxIds` gives each charge item's own tax ids by item id, so a
 * row starts with them. `earliestDate` and `businessDate` bound a row's service date. `canViewCompany` lets the Add folio dialog offer a company payer. `batchKey` is the form's UUID.
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
     * @param  list<FolioRailItemData>  $rail
     * @param  array<int, ManualLineData>  $lines  The row menu Dialogs' data, by charge line id.
     * @param  list<array{value: string, label: string}>  $reasonOptions
     */
    public function __construct(
        public int $reservationId,
        public string $reference,
        public string $title,
        public int $folioId,
        public string $folioLabel,
        public ?string $reason,
        public array $reasonOptions,
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
        public array $rail,
        public ManualPreviewData $preview,
        public ?FolioData $posted,
        public array $lines,
        public bool $canViewCompany,
        public string $batchKey,
        public int $maxRows,
        public string $cancelUrl,
    ) {}
}
