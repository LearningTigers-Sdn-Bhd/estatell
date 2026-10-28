<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\CreateBooking;
use App\Data\Reservations\CollectNowData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\WalkInCollectData;
use App\Enums\StayType;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\WalkInPayment;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckInWalkIn
{
    public function __construct(
        private CreateBooking $createBooking,
        private WalkInPayment $walkInPayment,
        private CheckInAndCollect $checkInAndCollect,
        private CheckOutUnit $checkOutUnit,
    ) {}

    /**
     * Book the stay, check in every unit and take the payment, in one transaction. `CreateBooking` runs first: a
     * refusal comes back as `WalkInResult::bookingRefused()` and nothing is saved. When a payment is asked
     * (`$collect`), `WalkInPayment` totals the saved reservation for the ticked boxes, the same way the preview does,
     * and a total that is not `expectedTotal` rolls everything back (`amount_changed`). Then each unit, in unit order,
     * goes through `CheckInAndCollect` with the walk-in's method, reference and `$withoutCard`, and the key
     * `{uuid}:{unit id}`. `CheckInAndCollect` opens its own transaction, which is a savepoint inside this one, so a unit
     * that is refused (a check-in code, or a payment code) rolls back alone: it stays Confirmed, pays nothing, and the
     * other units go on. `CheckInUnit` syncs the reservation status after each check-in, so no sync is needed at the
     * end. A day use unit that started earlier today is checked in at its start (`asOf`). With `$checkedOutAt` (a day
     * use walk-in saved as already checked out) each unit is also checked out at that moment (`CheckOutUnit` with
     * `asOf`), and a unit refused at its check-in, its payment or its check-out rolls the whole walk-in back
     * (`WalkInResult::unitRefused()`), because the guest has already left. One `reservation.walk_in` entry logs the counts and the total collected. Permissions (`reservations.manage`,
     * `front-desk.check-in`, `payment.record`, `front-desk.check-in-without-card`) are checked by the route and the Form
     * Request. See docs/18-front-desk/01-check-in-and-check-out.md#walk-in-check-in.
     */
    public function __invoke(User $actor, Property $property, NewBookingData $data, ?WalkInCollectData $collect = null, bool $withoutCard = false, ?CarbonImmutable $checkedOutAt = null): WalkInResult
    {
        try {
            $pending = $this->walkInPayment->auditPendingRefusal($property, $data);

            if ($pending !== null) {
                return WalkInResult::bookingRefused($pending);
            }

            return DB::transaction(function () use ($actor, $property, $data, $collect, $withoutCard, $checkedOutAt): WalkInResult {
                $booking = ($this->createBooking)($actor, $property, $data);

                if ($booking->isRefused()) {
                    return WalkInResult::bookingRefused($booking);
                }

                $reservation = $booking->reservation;

                if ($collect !== null && ! $this->totalMatches($reservation, $collect)) {
                    throw new WalkInAmountChanged;
                }

                $key = (string) Str::uuid();
                $units = [];
                $collected = BigDecimal::zero();

                foreach ($reservation->units()->orderBy('id')->get() as $unit) {
                    /** @var ReservationUnit $unit */
                    $result = ($this->checkInAndCollect)($actor, $unit, null, $withoutCard, null, $collect === null ? null : new CollectNowData(
                        taxes: true,
                        deposit: $collect->deposit,
                        paymentMethodId: $collect->paymentMethodId,
                        reference: $collect->reference,
                        idempotencyKey: $key.':'.$unit->id,
                        roomCharges: $collect->roomCharges,
                    ), $this->startedAt($unit, $property));
                    $paid = array_reduce($result->payments, fn (BigDecimal $sum, Payment $payment): BigDecimal => $sum->plus($payment->amount), BigDecimal::zero());
                    $refusal = $result->stay->refusal ?? $result->paymentRefusal;
                    $collected = $collected->plus($paid);
                    $row = new WalkInUnitResult($unit->id, $this->label($unit, count($units)), $refusal, $result->stay->refusal === null && $result->paymentRefusal !== null, (string) $paid->toScale(2));

                    if ($checkedOutAt !== null) {
                        $refusal ??= ($this->checkOutUnit)($actor, $unit->refresh(), $checkedOutAt)->refusal;

                        if ($refusal !== null) {
                            throw new WalkInUnitRefused($refusal === $row->refusal ? $row : new WalkInUnitResult($unit->id, $row->label, $refusal, false, '0.00'));
                        }
                    }

                    $units[] = $row;
                }

                $done = count(array_filter($units, fn (WalkInUnitResult $unit): bool => $unit->isCheckedIn()));

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('reservation.walk_in')
                    ->withProperties([
                        'property_id' => $property->id,
                        'units' => count($units),
                        'checked_in' => $done,
                        'collected' => (string) $collected->toScale(2),
                    ])
                    ->log('Walk-in checked in');

                return WalkInResult::done($this->fresh($reservation), $units, (string) $collected->toScale(2));
            });
        } catch (WalkInAmountChanged) {
            return WalkInResult::amountChanged();
        } catch (WalkInUnitRefused $refused) {
            return WalkInResult::unitRefused($refused->unit);
        }
    }

    /**
     * When a walk-in day use started earlier today, the moment it started: the guest is checked in at Start, not at the
     * save. Null for an overnight unit and for a day use that starts later.
     */
    private function startedAt(ReservationUnit $unit, Property $property): ?CarbonImmutable
    {
        if ($unit->stay_type !== StayType::DayUse) {
            return null;
        }

        $timeZone = $property->settings->time_zone ?? config('app.timezone');
        $start = CarbonImmutable::parse($unit->arrival_date->toDateString().' '.$unit->arrival_time, $timeZone);

        return $start->isBefore(CarbonImmutable::now($timeZone)) ? $start : null;
    }

    private function totalMatches(Reservation $reservation, WalkInCollectData $collect): bool
    {
        $total = $this->walkInPayment->total($this->walkInPayment->forReservation($reservation), $collect->roomCharges, $collect->deposit);

        return $collect->expectedTotal !== '' && BigDecimal::of($total)->isEqualTo($collect->expectedTotal);
    }

    /**
     * "Room 106" (or the bed label) from the unit's first night, "Unit 2" when it has no room or bed.
     */
    private function label(ReservationUnit $unit, int $position): string
    {
        $night = $unit->nights()->with(['room', 'bed'])->orderBy('date')->first();

        return match (true) {
            $night?->room !== null => __('Room :number', ['number' => $night->room->number]),
            $night?->bed !== null => $night->bed->label,
            default => __('Unit :number', ['number' => $position + 1]),
        };
    }

    private function fresh(Reservation $reservation): Reservation
    {
        return $reservation->fresh() ?? $reservation;
    }
}
