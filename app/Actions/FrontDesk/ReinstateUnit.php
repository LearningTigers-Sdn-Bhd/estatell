<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class ReinstateUnit
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private AvailabilityService $availability,
        private RateQuoteService $rateQuoteService,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Move a Cancelled or NoShow unit back to Confirmed, with a reason. The unit row is locked first, and so is its
     * room type, so two staff cannot sell the last room together. Every check runs before the first write, so a
     * refusal writes and logs nothing. The stay must still be ahead (`stay_ended` when the business date is on or
     * after the departure date) and the room type must have a free unit on every night (`not_available`).
     *
     * The unit is priced again with `RateQuoteService` from its plan, dates, adults and the ages in
     * `reservation_unit_children`. A stay override stays: a night with an `override_amount` keeps it as its amount.
     * The room or bed of every night is cleared again, as a safeguard (cancel and no-show already released them).
     * Staff assign with `AssignRoom` or `AssignBed`. A fee already posted stays on the folio, and the
     * correction is `ReverseLine`. Permissions are checked by the route.
     * See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, string $reason): UnitStayResult
    {
        return DB::transaction(function () use ($actor, $unit, $reason): UnitStayResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! $locked->status->canTransitionTo(UnitStatus::Confirmed)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            $reason = trim($reason);

            if ($reason === '') {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_REASON_REQUIRED);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($businessDate->toDateString() >= $locked->departure_date->toDateString()) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_STAY_ENDED);
            }

            $roomType = RoomType::query()->lockForUpdate()->findOrFail($locked->room_type_id);
            $arrival = $locked->arrival_date->startOfDay();
            $departure = $locked->departure_date->startOfDay();

            if (array_any($this->availability->nights($roomType, $arrival, $departure), fn (NightAvailability $night): bool => $night->available < 1)) {
                return UnitStayResult::refused(UnitStayResult::REFUSAL_NOT_AVAILABLE);
            }

            $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
                $locked->ratePlan()->firstOrFail(),
                $roomType,
                $arrival,
                $departure,
                $locked->adults,
                $locked->childAges()->pluck('age')->map(fn (mixed $age): int => (int) $age)->all(),
                1,
            ));

            if ($quote->isRefused()) {
                return UnitStayResult::refused((string) $quote->refusal);
            }

            $nights = $locked->nights()->get()->keyBy(fn (UnitNight $night): string => $night->date->toDateString());
            $previousTotal = $nights->reduce(fn (Money $sum, UnitNight $night): Money => $sum->plus($night->amount), Money::zero($quote->total->getCurrency()));

            foreach ($quote->nights as $price) {
                $night = $nights->get($price->date->toDateString());

                if ($night === null) {
                    continue;
                }

                $night->forceFill([
                    'room_id' => null,
                    'bed_id' => null,
                    'base_amount' => (string) $price->base->getAmount(),
                    'extra_amount' => (string) $price->extra->getAmount(),
                    'supplement_amount' => (string) $price->supplement->getAmount(),
                    'discount_amount' => (string) $price->discountAmount->getAmount(),
                    'amount' => $night->override_amount ?? (string) $price->net()->getAmount(),
                ])->save();
            }

            $previousStatus = $locked->status;
            $locked->status = UnitStatus::Confirmed;
            $locked->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_reinstated')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'from_status' => $previousStatus->value,
                    'reason' => $reason,
                    'previous_total' => (string) $previousTotal->getAmount(),
                    'total' => (string) $locked->nights()->sum('amount'),
                    'business_date' => $businessDate->toDateString(),
                ])
                ->log('Unit reinstated');

            ($this->syncReservationStatus)($locked->reservation);

            return UnitStayResult::done($locked);
        });
    }
}
