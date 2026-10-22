<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * The open folio of the folio tab. `number` is the folio's own reference (null for a unit with no folio yet). Balance is the posted lines only (null for a unit with no folio yet). Projected
 * total is the posted lines plus the Scheduled rows. `canClose` is true for an Open folio when the user holds the permission (a balance other than 0 is refused by the Action). `canReopen` is true for a Closed folio the user may reopen (`FolioReopenRule`), and
 * `closedNotice` is the text above its rows that says what to do. `canApplyDiscount` says an Open folio has a charge that can still take a discount. `postUnits` are the units a charge can be posted for (empty when the user may not post), and
 * `canManageManually` says an Open folio can be managed on the manual folio page and the user holds `folio.manual`.
 * `oldSystemTitle` and `oldSystemTotal` name the OLD SYSTEM section and sum its rows (its rows have the state `old` and come first); both are null when the folio has no migrated line.
 * `canMoveCharges` says an Open folio has another Open folio to move charges with and the user holds `folio.transfer`
 * (it shows **Move charges** in the header and **Move…** in the row menu of a movable charge).
 * `canRecordPayment` is true for an Open folio or a "not started" entry when the user holds `payment.record`, and
 * `paymentUnitId` is the unit a "not started" entry records on. `canPrintVoucher` is true when the user holds
 * `folio.print-voucher` and the folio has a line that can go on a voucher. `canSettleToCityLedger` is true for an Open
 * folio paid by a company with a balance above 0, when the user holds `city-ledger.invoice`, and `payerCompanyName` names
 * the company for the confirmation. `settleCreditWarning` is set only then, when the company is over its credit limit; it
 * warns and never stops the Settle. `paymentPurposes` are the purposes the Record payment Dialog offers for this folio's
 * stage, the default first, and `defaultPaymentPurpose` is that first one. With none, `canRecordPayment` is false.
 * `arInvoiceId` is the AR invoice that bills a Closed folio. `canOpenArInvoice` needs `company.view` and `canCreditArInvoice` needs the credit note permission, and the invoice must not be Void (it never is here). `canVoidAndReopen` needs `city-ledger.invoice` and `folio.reopen`; `voidInvoiceBlockedBy` is then the reason the invoice cannot be voided yet, or null. `invoiceVoided` is true when the folio's invoice number belongs only to Void AR invoices. `canPrintInvoice` is true for a Closed folio when the user holds `folio.print-voucher`.
 * `refundCredit` is the most the **Refund credit** button can give back (the credit, never more than the payments hold), or null when the folio is not Open, has no credit, holds money no payment can return, or the user lacks `payment.refund`. `canRefundOtherMethod` is true when the user holds `payment.refund-other-method`.
 */
class FolioData extends Data
{
    /**
     * @param  list<FolioRowData>  $rows
     * @param  list<FolioOptionData>  $postUnits
     * @param  list<PostFolioOptionData>  $postFolios  The Open folios the Post charge Dialog can pick.
     * @param  list<FolioOptionData>  $postRooms  Every unit a charge can be posted for.
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
        public array $postFolios = [],
        public array $postRooms = [],
        public bool $canMoveCharges = false,
        public bool $canRecordPayment = false,
        public ?int $paymentUnitId = null,
        public bool $canPrintVoucher = false,
        public bool $canSettleToCityLedger = false,
        public ?string $payerCompanyName = null,
        public array $paymentPurposes = [],
        public ?string $defaultPaymentPurpose = null,
        public ?string $closedNotice = null,
        public bool $canManageManually = false,
        public ?string $settleCreditWarning = null,
        public bool $canPrintInvoice = false,
        public ?int $arInvoiceId = null,
        public bool $canOpenArInvoice = false,
        public bool $canCreditArInvoice = false,
        public ?string $arInvoiceNumber = null,
        public bool $canVoidAndReopen = false,
        public bool $invoiceVoided = false,
        public ?string $voidInvoiceBlockedBy = null,
        public ?string $oldSystemTitle = null,
        public ?string $oldSystemTotal = null,
        public ?string $refundCredit = null,
        public bool $canRefundOtherMethod = false,
    ) {}
}
