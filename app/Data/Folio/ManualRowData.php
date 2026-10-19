<?php

namespace App\Data\Folio;

use App\Enums\ManualRowType;
use App\Enums\PaymentPurpose;
use Spatie\LaravelData\Data;

/**
 * One draft row of the manual folio page. `key` is the browser's id for the row; it names the row in a refusal and
 * ends the idempotency key of its line. `serviceDate` is a `Y-m-d` day. A Charge row gives `chargeItemId`, `quantity`,
 * `amount` (the unit amount), `description`, `taxIds` (null means the item's own taxes) and, on a Master or Third
 * party folio, `unitId`. A Payment row gives `paymentMethodId`, `purpose` (null means Settlement), `amount` and `reference`. A Refund row gives `paymentId`,
 * `amount` and `reference`, and posts on that payment's own folio whatever `folioId` says.
 * See docs/13-folio/08-manual-folio.md.
 */
class ManualRowData extends Data
{
    /**
     * @param  list<int>|null  $taxIds
     */
    public function __construct(
        public string $key,
        public ManualRowType $type,
        public string $serviceDate,
        public int $folioId,
        public ?int $unitId = null,
        public ?int $chargeItemId = null,
        public ?string $description = null,
        public int $quantity = 1,
        public string $amount = '',
        public ?array $taxIds = null,
        public ?int $paymentMethodId = null,
        public ?int $paymentId = null,
        public ?string $reference = null,
        public ?PaymentPurpose $purpose = null,
    ) {}
}
