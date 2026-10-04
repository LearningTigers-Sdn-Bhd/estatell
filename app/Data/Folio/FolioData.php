<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * The open folio of the folio tab. `number` is the folio's own reference (null for a unit with no folio yet). Balance is the posted lines only (null for a unit with no folio yet). Projected
 * total is the posted lines plus the Scheduled rows. `canClose` is true for an Open folio and `canReopen` for a Closed one, when the user holds the permission (a balance other than 0 is refused by the Action). `canApplyDiscount` says an Open folio has a charge that can still take a discount. `postUnits` are the units a charge can be posted for, and
 * `transferTargets` are the other Open folios of the reservation. Both are empty when the user may not act.
 * `canRecordPayment` is true for an Open folio or a "not started" entry when the user holds `payment.record`, and
 * `paymentUnitId` is the unit a "not started" entry records on. `canPrintVoucher` is true when the user holds
 * `folio.print-voucher` and the folio has a line that can go on a voucher.
 */
class FolioData extends Data
{
    /**
     * @param  list<FolioRowData>  $rows
     * @param  list<FolioOptionData>  $postUnits
     * @param  list<FolioOptionData>  $transferTargets
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $name,
        public ?BadgeData $status,
        public ?string $number,
        public ?string $invoiceNumber,
        public ?string $balance,
        public string $projectedTotal,
        public int $lineCount,
        public array $rows,
        public bool $canClose = false,
        public bool $canReopen = false,
        public bool $canApplyDiscount = false,
        public bool $canPostCharge = false,
        public array $postUnits = [],
        public array $transferTargets = [],
        public bool $canRecordPayment = false,
        public ?int $paymentUnitId = null,
        public bool $canPrintVoucher = false,
    ) {}
}
