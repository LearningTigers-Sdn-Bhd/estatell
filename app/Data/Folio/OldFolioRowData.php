<?php

namespace App\Data\Folio;

use App\Enums\OldFolioRowKind;
use Spatie\LaravelData\Data;

/**
 * One row of the old folio page, sent to `SaveOldFolios` and shown on the page. `key` is a UUID made when the row is
 * created: the line key is `migrated:{folio_id}:{key}`. `serviceDate` is a `Y-m-d` day before the go-live date. A Room
 * row gives `unitId` (the room night's unit) and posts as a Room charge. A Charge row gives `chargeItemId`, a Tax row
 * `taxId` and a Payment row `paymentMethodId` (the Old system method), with the old receipt number as `description`.
 * `amount` is the amount of one unit; a Tax and a Payment row always have a quantity of 1. `lineId` is set on a saved row.
 * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
 */
class OldFolioRowData extends Data
{
    public function __construct(
        public string $key,
        public OldFolioRowKind $kind,
        public string $serviceDate,
        public int $folioId,
        public ?int $unitId = null,
        public ?int $chargeItemId = null,
        public ?int $taxId = null,
        public ?int $paymentMethodId = null,
        public ?string $description = null,
        public int $quantity = 1,
        public string $amount = '',
        public ?int $lineId = null,
    ) {}
}
