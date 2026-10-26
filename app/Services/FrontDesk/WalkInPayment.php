<?php

namespace App\Services\FrontDesk;

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\WalkInPaymentData;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * What a walk-in would pay, before anything is kept. It books the stay as a trial inside a transaction, reads every
 * unit through `CheckInCollection`, and always rolls back, so nothing stays: no reservation, guest, activity log,
 * reference number or channel outbox line. Because the trial runs the real `CreateBooking`, the amounts are the
 * folio's own. A booking that `CreateBooking` refuses returns its `BookingResult`. See
 * docs/18-front-desk/01-check-in-and-check-out.md#walk-in-check-in.
 */
class WalkInPayment
{
    public function __construct(
        private CreateBooking $createBooking,
        private CheckInCollection $collection,
    ) {}

    /**
     * The payment of the walk-in, or the refusal of its booking.
     */
    public function preview(User $actor, Property $property, NewBookingData $data): WalkInPaymentData|BookingResult
    {
        DB::beginTransaction();

        try {
            $result = ($this->createBooking)($actor, $property, $data);

            if ($result->isRefused()) {
                return $result;
            }

            return $this->forReservation($result->reservation);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * What the units of a saved reservation pay, from `CheckInCollection`. The preview reads it inside the trial
     * booking and `CheckInWalkIn` reads it from the saved one, so the two cannot differ in how they add up.
     */
    public function forReservation(Reservation $reservation): WalkInPaymentData
    {
        $roomCharges = BigDecimal::zero();
        $deposit = BigDecimal::zero();
        $depositUnits = 0;
        $depositAmount = null;
        $hidden = false;
        $taxes = [];

        foreach ($reservation->units()->orderBy('id')->get() as $unit) {
            $collected = $this->collection->for($unit);
            $roomCharges = $roomCharges->plus($collected->billTotal);
            $hidden = $collected->billHidden;

            if ($collected->deposit !== null) {
                $depositUnits++;
                $depositAmount = $collected->deposit;
                $deposit = $deposit->plus($collected->deposit);
            }

            foreach ($collected->taxes as $tax) {
                $row = $taxes[$tax['name']] ?? ['name' => $tax['name'], 'amount' => BigDecimal::zero(), 'exempt' => true];
                $taxes[$tax['name']] = [
                    'name' => $tax['name'],
                    'amount' => $row['amount']->plus($tax['amount']),
                    'exempt' => $row['exempt'] && $tax['exempt'],
                ];
            }
        }

        $taxRows = array_values(array_map(fn (array $row): array => [
            'name' => $row['name'],
            'amount' => (string) $row['amount']->toScale(2),
            'exempt' => $row['exempt'],
        ], $taxes));
        $taxesTotal = array_reduce($taxes, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['amount']), BigDecimal::zero());

        return new WalkInPaymentData(
            roomCharges: (string) $roomCharges->toScale(2),
            roomChargesHidden: $hidden,
            taxes: $taxRows,
            taxesTotal: (string) $taxesTotal->toScale(2),
            deposit: $depositUnits === 0 ? null : ['amount' => (string) BigDecimal::of($depositAmount)->toScale(2), 'units' => $depositUnits, 'total' => (string) $deposit->toScale(2)],
            totalWithRoomCharges: (string) ($hidden ? $deposit : $roomCharges->plus($deposit))->toScale(2),
            totalWithoutRoomCharges: (string) $taxesTotal->plus($deposit)->toScale(2),
        );
    }

    /**
     * The total for the ticked boxes, the same pick `paymentTotal()` makes in the browser: with the deposit ticked and
     * set, one of the two server totals; without it the full bill or the tax rows. Room charges ticked while the company
     * is billed counts as unticked. It adds nothing up.
     */
    public function total(WalkInPaymentData $payment, bool $collectRoomCharges, bool $collectDeposit): string
    {
        $rooms = $collectRoomCharges && ! $payment->roomChargesHidden;

        if ($collectDeposit && $payment->deposit !== null) {
            return $rooms ? $payment->totalWithRoomCharges : $payment->totalWithoutRoomCharges;
        }

        return $rooms ? $payment->roomCharges : $payment->taxesTotal;
    }
}
