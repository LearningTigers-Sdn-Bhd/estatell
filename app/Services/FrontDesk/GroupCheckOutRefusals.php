<?php

namespace App\Services\FrontDesk;

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\Folio\PostingResult;
use App\Actions\FrontDesk\CheckOutGroupResult;
use App\Actions\Payments\PaymentResult;
use App\Services\CityLedger\ArInvoiceRefusals;
use App\Services\Folio\FolioRefusals;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Payments\PaymentRefusals;
use App\Services\Reservations\ReservationRefusals;

/**
 * Turns the refusal of `CheckOutGroup` into the errors of the page: a room's refusal is keyed `units.{id}` and starts
 * with the room's name ("Room 108: ..."), any other one is keyed `form`. The step codes pass through to the message
 * lists of their own modules.
 */
class GroupCheckOutRefusals
{
    public function __construct(
        private ReservationRefusals $reservationRefusals,
        private PaymentRefusals $paymentRefusals,
        private FolioRefusals $folioRefusals,
        private ArInvoiceRefusals $arInvoiceRefusals,
        private IncomingScheduledCharges $incomingScheduledCharges,
    ) {}

    /**
     * @return array<string, string>
     */
    public function errors(CheckOutGroupResult $result): array
    {
        $message = $this->message((string) $result->refusal, $result->rooms, $result->unitId === null);

        if ($result->unitId === null) {
            return ['form' => $message];
        }

        $room = $this->incomingScheduledCharges->unitNames([$result->unitId])[$result->unitId] ?? '—';

        return ['units.'.$result->unitId => $room.': '.$message];
    }

    /**
     * @param  list<string>  $rooms
     */
    public function message(string $code, array $rooms = [], bool $master = false): string
    {
        return match (true) {
            $code === CheckOutGroupResult::REFUSAL_ROW_NOT_AVAILABLE => __('This room cannot be checked out here.'),
            $code === CheckOutGroupResult::REFUSAL_UNIT_NOT_FOUND => __('This room is not on the reservation.'),
            $code === CheckOutGroupResult::REFUSAL_DUPLICATE_ROW => __('A room is listed twice.'),
            $code === CheckOutGroupResult::REFUSAL_INVALID_CHOICE => __('Choose one way to settle this room.'),
            $code === CheckOutGroupResult::REFUSAL_NO_MASTER_FOLIO => __('The reservation has no open Master folio.'),
            $code === CheckOutGroupResult::REFUSAL_NOTHING_TO_MOVE => __('The room has a balance but no charge to move.'),
            $code === CheckOutGroupResult::REFUSAL_PAYMENT_NOT_REFUNDABLE => __('The folio holds a payment that cannot be refunded, so its charges cannot move.'),
            $code === CheckOutGroupResult::REFUSAL_INVALID_PAYER => __('The room that pays must be checked in or checked out, with an open folio.'),
            $code === CheckOutGroupResult::REFUSAL_AMOUNT_CHANGED => __('The total of the one payment changed. Check the new amount and save again.'),
            $code === CheckOutGroupResult::REFUSAL_NOTHING_TO_COLLECT => __('There is nothing to collect in one payment.'),
            $master && in_array($code, [ArInvoiceResult::REFUSAL_UNITS_IN_HOUSE, ArInvoiceResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, ArInvoiceResult::REFUSAL_NO_COMPANY_PAYER, ArInvoiceResult::REFUSAL_DIRECT_BILL_NOT_ALLOWED, ArInvoiceResult::REFUSAL_NOTHING_TO_SETTLE, ArInvoiceResult::REFUSAL_FOLIO_NOT_OPEN], true) => $this->arInvoiceRefusals->message($code, $rooms),
            in_array($code, [PostingResult::REFUSAL_TRANSFER_INVALID, PostingResult::REFUSAL_DIFFERENT_RESERVATION, PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE, PostingResult::REFUSAL_PAYMENT_LINE], true) => $this->folioRefusals->message($code),
            in_array($code, [PaymentResult::REFUSAL_PAYMENT_NOT_FOUND, PaymentResult::REFUSAL_NOT_REFUNDABLE, PaymentResult::REFUSAL_AMOUNT_INVALID, PaymentResult::REFUSAL_DEPOSIT_ON_UNIT_ONLY, PaymentResult::REFUSAL_ADVANCE_ON_MASTER], true) => $this->paymentRefusals->message($code),
            default => array_values($this->reservationRefusals->unitErrors($code, rooms: $rooms))[0],
        };
    }
}
