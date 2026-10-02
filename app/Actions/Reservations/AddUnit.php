<?php

namespace App\Actions\Reservations;

use App\Data\Reservations\NewUnitData;
use App\Enums\DiscountScope;
use App\Enums\PermissionEnum;
use App\Enums\ReservationSource;
use App\Enums\UnitStatus;
use App\Models\Discount;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class AddUnit
{
    public function __construct(
        private RestrictionCheck $restrictionCheck,
        private AvailabilityService $availability,
        private RateQuoteService $rateQuoteService,
        private SyncReservationStatus $syncReservationStatus,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * Add exactly one unit (one room or one bed) to a reservation and ignore `beds`. Callers loop for bed lines.
     *
     * The room type row is locked before the availability check, so two staff cannot sell the last unit together.
     * Every check runs before the first write, so a refusal writes and logs nothing. `$lineIndex` is the index of
     * the line in the request, and comes back on a refusal. Channel bookings skip the arrival date, restriction
     * and availability checks. See docs/12-reservations/02-group-bookings.md.
     */
    public function __invoke(User $actor, Reservation $reservation, NewUnitData $data, int $lineIndex = 0): ReservationResult
    {
        return DB::transaction(function () use ($actor, $reservation, $data, $lineIndex): ReservationResult {
            $isChannel = $reservation->source === ReservationSource::Channel;
            $refuse = fn (string $code, $date = null): ReservationResult => ReservationResult::refused($code, $lineIndex, $date);

            $roomType = RoomType::query()
                ->where('property_id', $reservation->property_id)
                ->active()
                ->lockForUpdate()
                ->find($data->roomTypeId);
            $ratePlan = RatePlan::query()->where('property_id', $reservation->property_id)->find($data->ratePlanId);

            if ($roomType === null || $ratePlan === null) {
                return $refuse(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND);
            }

            if (! $ratePlan->roomTypes()->whereKey($roomType->id)->exists()) {
                return $refuse(ReservationResult::REFUSAL_RATE_PLAN_NOT_LINKED);
            }

            $arrival = $data->arrivalDate->startOfDay();
            $departure = $data->departureDate->startOfDay();

            if ($departure->lte($arrival)) {
                return $refuse(ReservationResult::REFUSAL_INVALID_STAY);
            }

            $property = $reservation->property;

            if (! $isChannel) {
                $today = $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);

                if ($arrival->lt($today)) {
                    return $refuse(ReservationResult::REFUSAL_ARRIVAL_IN_PAST);
                }
            }

            if ($data->adults < 1) {
                return $refuse(ReservationResult::REFUSAL_ADULTS_REQUIRED);
            }

            if (array_any($data->childAges, fn (mixed $age): bool => ! is_int($age) || $age < 0 || $age > 17)) {
                return $refuse(ReservationResult::REFUSAL_CHILD_AGE_INVALID);
            }

            if ($data->discountId !== null && ! $this->isRoomOnlyDiscountOf($reservation, $data->discountId)) {
                return $refuse(ReservationResult::REFUSAL_DISCOUNT_NOT_ALLOWED);
            }

            $restrictionOverride = null;

            if (! $isChannel) {
                $violations = $this->restrictionCheck->violations($ratePlan->id, $roomType->id, $arrival, $departure);

                if ($violations !== []) {
                    $reason = trim((string) $data->restrictionOverrideReason);

                    if ($reason === '') {
                        return $refuse(ReservationResult::REFUSAL_RESTRICTED);
                    }

                    if (! $this->canOverrideRestrictions($actor, $reservation)) {
                        return $refuse(ReservationResult::REFUSAL_RESTRICTION_OVERRIDE_NOT_ALLOWED);
                    }

                    $restrictionOverride = ['reason' => $reason, 'violations' => $violations];
                }

                $soldOut = array_find(
                    $this->availability->nights($roomType, $arrival, $departure),
                    fn (NightAvailability $night): bool => $night->available < 1,
                );

                if ($soldOut !== null) {
                    return $refuse(ReservationResult::REFUSAL_NOT_AVAILABLE, $soldOut->date);
                }
            }

            $overrideTotal = null;

            if ($data->overrideTotal !== null) {
                if (trim((string) $data->overrideReason) === '') {
                    return $refuse(ReservationResult::REFUSAL_OVERRIDE_REASON_REQUIRED);
                }

                if (! is_numeric($data->overrideTotal) || (float) $data->overrideTotal < 0) {
                    return $refuse(ReservationResult::REFUSAL_OVERRIDE_INVALID);
                }
            }

            $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
                $ratePlan,
                $roomType,
                $arrival,
                $departure,
                $data->adults,
                array_values($data->childAges),
                1,
            ));

            if ($quote->isRefused()) {
                return $refuse((string) $quote->refusal, $quote->refusalDate);
            }

            if ($data->overrideTotal !== null) {
                $overrideTotal = Money::of($data->overrideTotal, $quote->total->getCurrency(), roundingMode: RoundingMode::HalfUp);
            }

            $unit = $reservation->units()->make([
                'room_type_id' => $roomType->id,
                'rate_plan_id' => $ratePlan->id,
                'arrival_date' => $arrival,
                'departure_date' => $departure,
                'adults' => $data->adults,
                'children' => count($data->childAges),
                'discount_id' => $data->discountId,
                'late_arrival_expected' => $data->lateArrivalExpected,
            ]);
            $unit->forceFill(['property_id' => $reservation->property_id, 'status' => UnitStatus::Confirmed])->save();

            $overrides = $overrideTotal === null ? null : $this->spread($overrideTotal, count($quote->nights));

            $unit->nights()->createMany(array_map(
                fn (NightPrice $night, int $index): array => [
                    'date' => $night->date->toDateString(),
                    'rate_plan_id' => $ratePlan->id,
                    'base_amount' => (string) $night->base->getAmount(),
                    'extra_amount' => (string) $night->extra->getAmount(),
                    'supplement_amount' => (string) $night->supplement->getAmount(),
                    'discount_amount' => (string) $night->discountAmount->getAmount(),
                    'override_amount' => $overrides === null ? null : (string) $overrides[$index]->getAmount(),
                    'amount' => (string) ($overrides === null ? $night->net() : $overrides[$index])->getAmount(),
                ],
                $quote->nights,
                array_keys($quote->nights),
            ));

            $unit->childAges()->createMany(array_map(fn (int $age): array => ['age' => $age], array_values($data->childAges)));

            $this->log($actor, $reservation, $unit, $restrictionOverride, $overrideTotal, $data->overrideReason);

            ($this->syncReservationStatus)($reservation);

            return ReservationResult::created($reservation);
        });
    }

    private function isRoomOnlyDiscountOf(Reservation $reservation, int $discountId): bool
    {
        return Discount::query()
            ->where('property_id', $reservation->property_id)
            ->active()
            ->where('scope', DiscountScope::RoomOnly)
            ->whereKey($discountId)
            ->exists();
    }

    /**
     * Whether the actor may book past restrictions in the reservation's property. The permission team is switched to
     * that property and put back afterwards.
     */
    private function canOverrideRestrictions(User $actor, Reservation $reservation): bool
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($reservation->property_id);

        try {
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            return $actor->can(PermissionEnum::RatesOverrideRestrictions->value);
        } finally {
            setPermissionsTeamId($previousTeamId);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Spread a stay total evenly over the nights, rounded down to the cent. The last night takes the remainder.
     *
     * @return list<Money>
     */
    private function spread(Money $total, int $nights): array
    {
        $even = $total->dividedBy($nights, RoundingMode::Down);
        $amounts = array_fill(0, $nights - 1, $even);
        $amounts[] = $total->minus($even->multipliedBy($nights - 1));

        return $amounts;
    }

    /**
     * @param  array{reason: string, violations: list<string>}|null  $restrictionOverride
     */
    private function log(User $actor, Reservation $reservation, ReservationUnit $unit, ?array $restrictionOverride, ?Money $overrideTotal, ?string $overrideReason): void
    {
        $properties = [
            'property_id' => $reservation->property_id,
            'reservation_unit_id' => $unit->id,
            'room_type_id' => $unit->room_type_id,
            'rate_plan_id' => $unit->rate_plan_id,
            'arrival_date' => $unit->arrival_date->toDateString(),
            'departure_date' => $unit->departure_date->toDateString(),
        ];

        if ($restrictionOverride !== null) {
            $properties['restriction_override_reason'] = $restrictionOverride['reason'];
            $properties['restriction_violations'] = $restrictionOverride['violations'];
        }

        if ($overrideTotal !== null) {
            $properties['override_total'] = (string) $overrideTotal->getAmount();
            $properties['override_reason'] = trim((string) $overrideReason);
        }

        activity()
            ->performedOn($reservation)
            ->causedBy($actor)
            ->event('reservation.unit_added')
            ->withProperties($properties)
            ->log('Unit added to reservation');
    }
}
