<?php

namespace App\Services\FrontDesk;

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\WalkInPaymentData;
use App\Enums\BillingMode;
use App\Models\Property;
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

            return $this->payment($result, $data);
        } finally {
            DB::rollBack();
        }
    }

    private function payment(BookingResult $result, NewBookingData $data): WalkInPaymentData
    {
        $roomCharges = BigDecimal::zero();
        $deposit = BigDecimal::zero();
        $depositUnits = 0;
        $depositAmount = null;
        $taxes = [];

        foreach ($result->reservation->units()->orderBy('id')->get() as $unit) {
            $collected = $this->collection->for($unit);
            $roomCharges = $roomCharges->plus($collected->billTotal);

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
        $hidden = $data->companyId !== null && $data->billingMode !== BillingMode::EachPays;

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
}
