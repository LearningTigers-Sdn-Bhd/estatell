<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\SyncRoutingRules;
use App\Data\Reservations\NewUnitData;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Availability\DayUseAvailability;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Rates\DayUseOffers;
use App\Services\Rates\RateQuote;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use Brick\Money\Money;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AddDayUseUnit
{
    public function __construct(
        private RestrictionCheck $restrictionCheck,
        private DayUseAvailability $availability,
        private DayUseOffers $offers,
        private RateQuoteService $rateQuoteService,
        private SyncReservationStatus $syncReservationStatus,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private AddUnitAddOn $addUnitAddOn,
        private LogDeclinedAutoAddOns $logDeclinedAutoAddOns,
        private SyncRoutingRules $syncRoutingRules,
    ) {}

    /**
     * Add one day use unit: a room for a number of hours on one date. `AddUnit` calls it inside its transaction, with the
     * room type already locked, so every check runs before the first write and a refusal writes and logs nothing.
     * The unit has no nights. Its `unit_day_uses` row holds the room, the hours and the quoted price, and the unit's
     * `arrival_time` and `departure_time` equal the start and the end of the block.
     *
     * A per-night plan (Per room or Per guest) is the placeholder plan of an entered price: it needs `overrideTotal` (`amount_required`
     * without it), and then the offer window, the hour price and the restriction check are skipped.
     *
     * The room is required and is locked, then checked again with `DayUseAvailability`. The exclusion constraint on
     * `unit_day_uses` is the last guard. A price override has an optional logged reason; a catalogue discount is refused. A Stop sell date is
     * refused unless the caller may book past restrictions (`$mayOverride`) and gave a reason.
     * See docs/12-reservations/10-day-use.md.
     *
     * @param  Closure(): bool  $mayOverride  Whether the actor may book past rate restrictions.
     *
     * @throws ReservationRefused when an add-on or the database refuses after the unit was written
     */
    public function __invoke(User $actor, Reservation $reservation, NewUnitData $data, RoomType $roomType, RatePlan $ratePlan, int $lineIndex, bool $fromChannel, Closure $mayOverride): ReservationResult
    {
        $refuse = fn (string $code, $date = null): ReservationResult => ReservationResult::refused($code, $lineIndex, $date);

        if ($fromChannel) {
            return $refuse(ReservationResult::REFUSAL_DAY_USE_NOT_FOR_CHANNEL);
        }

        if ($roomType->sell_unit !== SellUnit::Room) {
            return $refuse(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND);
        }

        $enteredPrice = $ratePlan->pricing_basis !== PricingBasis::DayUse;

        if ($enteredPrice && (! in_array($ratePlan->pricing_basis, [PricingBasis::PerRoom, PricingBasis::PerGuest], true) || $ratePlan->archived_at !== null)) {
            return $refuse(RateQuote::REFUSAL_PLAN_NOT_DAY_USE);
        }

        $date = $data->arrivalDate->startOfDay();

        if (! $data->departureDate->startOfDay()->equalTo($date)) {
            return $refuse(ReservationResult::REFUSAL_INVALID_STAY);
        }

        $property = $reservation->property;
        $today = $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);

        if ($date->lt($today)) {
            return $refuse(ReservationResult::REFUSAL_ARRIVAL_IN_PAST);
        }

        if ($data->adults < 1) {
            return $refuse(ReservationResult::REFUSAL_ADULTS_REQUIRED);
        }

        if (array_any($data->childAges, fn (int $age): bool => $age < 0 || $age > 17)) {
            return $refuse(ReservationResult::REFUSAL_CHILD_AGE_INVALID);
        }

        if ($data->discountId !== null) {
            return $refuse(ReservationResult::REFUSAL_DISCOUNT_NOT_ALLOWED);
        }

        if ($data->overrideTotal !== null && preg_match('/^\d+(\.\d{1,2})?$/', $data->overrideTotal) !== 1) {
            return $refuse(ReservationResult::REFUSAL_OVERRIDE_INVALID);
        }

        if ($enteredPrice && $data->overrideTotal === null) {
            return $refuse(ReservationResult::REFUSAL_AMOUNT_REQUIRED);
        }

        if ($data->startTime === null || $data->hours === null) {
            return $refuse($data->startTime === null ? ReservationResult::REFUSAL_START_TIME_INVALID : RateQuote::REFUSAL_HOURS_NOT_SOLD);
        }

        $blockRefusal = $enteredPrice
            ? $this->offers->enteredRefusal($data->startTime, $data->hours)
            : $this->offers->refusal($ratePlan, $data->startTime, $data->hours);

        if ($blockRefusal !== null) {
            return $refuse($blockRefusal);
        }

        $startsAt = DayUseOffers::time(DayUseOffers::minutes($data->startTime));
        $endsAt = (string) DayUseOffers::endsAt($startsAt, $data->hours);

        if ($data->roomId === null) {
            return $refuse(ReservationResult::REFUSAL_ROOM_REQUIRED);
        }

        $room = Room::query()
            ->where('property_id', $reservation->property_id)
            ->where('room_type_id', $roomType->id)
            ->active()
            ->lockForUpdate()
            ->find($data->roomId);

        if ($room === null) {
            return $refuse(AssignmentResult::REFUSAL_ROOM_NOT_FOUND);
        }

        $violations = $enteredPrice ? [] : $this->restrictionCheck->dayUseViolations($ratePlan->id, $roomType->id, $date);
        $restrictionOverride = null;

        if ($violations !== []) {
            $reason = trim((string) $data->restrictionOverrideReason);

            if ($reason === '') {
                return $refuse(ReservationResult::REFUSAL_RESTRICTED, $date);
            }

            if (! $mayOverride()) {
                return $refuse(ReservationResult::REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED);
            }

            $restrictionOverride = ['reason' => $reason, 'violations' => $violations];
        }

        if (! $this->availability->isFree($roomType, $room->id, $date, $startsAt, $endsAt)) {
            return $refuse(ReservationResult::REFUSAL_NOT_AVAILABLE, $date);
        }

        if ($enteredPrice) {
            $quotedAmount = null;
            $currency = $reservation->property->settings->currency->getCurrencyCode();
        } else {
            $quote = $this->rateQuoteService->quoteDayUse($roomType, $ratePlan, $date, $data->hours, $restrictionOverride !== null);

            if ($quote->isRefused()) {
                return $refuse((string) $quote->refusal, $quote->refusalDate);
            }

            $quotedAmount = $quote->amount;
            $currency = $quote->amount->getCurrency();
        }

        $overrideTotal = $data->overrideTotal === null ? null : Money::of($data->overrideTotal, $currency);

        $unit = $reservation->units()->make([
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
            'arrival_date' => $date,
            'departure_date' => $date,
            'arrival_time' => $startsAt,
            'departure_time' => $endsAt,
            'adults' => $data->adults,
            'children' => count($data->childAges),
            'late_arrival_expected' => $data->lateArrivalExpected,
        ]);
        $unit->forceFill(['property_id' => $reservation->property_id, 'status' => UnitStatus::Confirmed, 'stay_type' => StayType::DayUse])->save();

        try {
            DB::transaction(fn () => $unit->dayUse()->create([
                'date' => $date->toDateString(),
                'room_id' => $room->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'hours' => $data->hours,
                'amount' => (string) ($overrideTotal ?? $quotedAmount)->getAmount(),
                'override_amount' => $overrideTotal === null ? null : (string) $overrideTotal->getAmount(),
            ]));
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw new ReservationRefused(ReservationResult::refused(ReservationResult::REFUSAL_NOT_AVAILABLE, $lineIndex, $date));
            }

            throw $exception;
        }

        $unit->childAges()->createMany(array_map(fn (int $age): array => ['age' => $age], $data->childAges));

        foreach ($data->addOns as $addOn) {
            $addOnResult = ($this->addUnitAddOn)($actor, $unit, $addOn);

            if ($addOnResult->isRefused()) {
                throw new ReservationRefused(ReservationResult::refused((string) $addOnResult->refusal, $lineIndex));
            }
        }

        ($this->logDeclinedAutoAddOns)($actor, $reservation, $unit, $data->declinedAutoAddOns);

        $this->log($actor, $reservation, $unit, $room, $data->hours, $startsAt, $endsAt, $restrictionOverride, $overrideTotal, $data->overrideReason);

        ($this->syncReservationStatus)($reservation);

        $routing = ($this->syncRoutingRules)($reservation);

        return ReservationResult::created($reservation, $routing->unitsWithPostedRoomLines, $routing->unitAdvances);
    }

    /**
     * @param  array{reason: string, violations: list<string>}|null  $restrictionOverride
     */
    private function log(User $actor, Reservation $reservation, ReservationUnit $unit, Room $room, int $hours, string $startsAt, string $endsAt, ?array $restrictionOverride, ?Money $overrideTotal, ?string $overrideReason): void
    {
        $properties = [
            'property_id' => $reservation->property_id,
            'reservation_unit_id' => $unit->id,
            'room_type_id' => $unit->room_type_id,
            'rate_plan_id' => $unit->rate_plan_id,
            'arrival_date' => $unit->arrival_date->toDateString(),
            'departure_date' => $unit->departure_date->toDateString(),
            'stay_type' => StayType::DayUse->value,
            'room_id' => $room->id,
            'hours' => $hours,
            'starts_at' => substr($startsAt, 0, 5),
            'ends_at' => substr($endsAt, 0, 5),
        ];

        if ($overrideTotal !== null) {
            $properties['override_total'] = (string) $overrideTotal->getAmount();
            $properties['override_reason'] = trim((string) $overrideReason) === '' ? null : trim((string) $overrideReason);
        }

        if ($restrictionOverride !== null) {
            $properties['restriction_override_reason'] = $restrictionOverride['reason'];
            $properties['restriction_violations'] = $restrictionOverride['violations'];
        }

        activity()
            ->performedOn($reservation)
            ->causedBy($actor)
            ->event('reservation.unit_added')
            ->withProperties($properties)
            ->log('Unit added to reservation');
    }
}
