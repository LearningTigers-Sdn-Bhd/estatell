<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Check out form shows when it opens: the balance of the unit folio, the security deposit held (a credit)
 * and how much of it a refund would give back so the folio reaches 0. `balance` is the plain folio sum, so a held
 * deposit makes it negative. `owing` is what the guest still has to pay, `folioKey` opens the folio tab. `canCollect`
 * (`payment.record`) shows Collect balance, and `canRefund` (`payment.refund`) the deposit refund. `early` is
 * set when the unit leaves before its departure date, and the fee in it is not yet in `balance`. `rooms` splits the
 * balance by room when the folio also holds another room's charges, and `otherLineIds` are those rooms' charges, ticked
 * when **Move charges** opens. `receivesScheduled` is set when `error` says another room still charges its scheduled
 * nights here. `canMoveCharges` (`folio.transfer`) shows the Move charges link. `refundable` is what the folios' payments
 * can give back in all (the deposit and any other payment), and `refundParts` lists each payment the default refund
 * takes money from, in refund order. A credit above the deposit is refunded as a whole. `charges` (every line that is
 * not a payment) and `paid` (every payment and refund but the deposit held, negative) break the balance down for the
 * Dialog: `charges + paid - depositHeld = balance`. `pendingLines` are the lines the check-out posts first for the night
 * of the business date while the night audit is pending (shown as added now), and `pendingTotal` is their sum, already
 * inside `balance` and `charges`. `overdue` is set for a guest due to leave on the business date while the audit is
 * pending (They left yesterday or They stayed last night). `auditPending` is the strip and the ⓘ line shown while the
 * audit is pending.
 */
class UnitCheckOutPreviewData extends Data
{
    public function __construct(
        public string $balance,
        public string $owing,
        public string $depositHeld,
        public string $refundDefault,
        public ?string $depositMethod,
        public bool $canRefund,
        public ?string $folioKey,
        public ?string $error,
        public bool $canCollect = false,
        public ?UnitEarlyDepartureData $early = null,
        /** @var list<CheckOutRoomShareData> */
        public array $rooms = [],
        /** @var list<int> */
        public array $otherLineIds = [],
        public bool $receivesScheduled = false,
        public bool $canMoveCharges = false,
        public string $refundable = '0.00',
        /** @var list<CheckOutRefundPartData> */
        public array $refundParts = [],
        public string $charges = '0.00',
        public string $paid = '0.00',
        /** @var list<UnitPendingLineData> */
        public array $pendingLines = [],
        public string $pendingTotal = '0.00',
        public ?UnitOverdueData $overdue = null,
        public ?UnitAuditPendingData $auditPending = null,
    ) {}

    public static function refused(string $error): self
    {
        return new self('0.00', '0.00', '0.00', '0.00', null, false, null, $error);
    }
}
