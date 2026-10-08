<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * The open folio of the folio tab. `number` is the folio's own reference (null for a unit with no folio yet). Balance is the posted lines only (null for a unit with no folio yet). Projected
 * total is the posted lines plus the Scheduled rows. `canClose` is true for an Open folio when the user holds the permission (a balance other than 0 is refused by the Action). `canReopen` is true for a Closed folio the user may reopen (`FolioReopenRule`), and
 * `closedNotice` is the text above its rows that says what to do. `canApplyDiscount` says an Open folio has a charge that can still take a discount. `postUnits` are the units a charge can be posted for, and
 * `transferTargets` are the other Open folios of the reservation. Both are empty when the user may not act.
 * `canRecordPayment` is true for an Open folio or a "not started" entry when the user holds `payment.record`, and
 * `paymentUnitId` is the unit a "not started" entry records on. `canPrintVoucher` is true when the user holds
 * `folio.print-voucher` and the folio has a line that can go on a voucher. `canSettleToCityLedger` is true for an Open
 * folio paid by a company with a balance above 0, when the user holds `city-ledger.invoice`, and `payerCompanyName` names
 * the company for the confirmation. `settleCreditWarning` is set only then, when the company is over its credit limit; it
 * warns and never stops the Settle. `paymentPurposes` are the purposes the Record payment Dialog offers for this folio's
 * stage, the default first, and `defaultPaymentPurpose` is that first one. With none, `canRecordPayment` is false.
 */
class FolioData extends Data
{
    /**
     * @param  list<FolioRowData>  $rows
     * @param  list<FolioOptionData>  $postUnits
     * @param  list<FolioOptionData>  $transferTargets
     * @param  list<array{value: string, label: string}>  $paymentPurposes
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
        public bool $canSettleToCityLedger = false,
        public ?string $payerCompanyName = null,
        public array $paymentPurposes = [],
        public ?string $defaultPaymentPurpose = null,
        public ?string $closedNotice = null,
        public ?string $settleCreditWarning = null,
    ) {}
}
