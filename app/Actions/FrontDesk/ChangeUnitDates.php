<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Events\ReservationChanged;
use App\Events\StayDatesChanged;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\FreeUnits;
use App\Services\Availability\NightAvailability;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Rates\RestrictionCheck;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChangeUnitDates
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private RestrictionCheck $restrictionCheck,
        private AvailabilityService $availability,
        private FreeUnits $freeUnits,
        private RateQuoteService $rateQuoteService,
        private ProcessEarlyDeparture $processEarlyDeparture,
        private AddNightlyAddOnRows $addNightlyAddOnRows,
        private SyncReservationStatus $syncReservationStatus,
    ) {}

    /**
     * Change the dates of one unit. A Confirmed unit can change arrival and departure. A CheckedIn unit can change
     * only its departure, and not to a date before the business date. The unit row and its room type row are locked
     * first. Every check runs before the first write, so a refusal writes and logs nothing.
     *
     * Nights that stay keep their stored prices, rate plan and room or bed: a rate change never touches an existing
     * booking. Nights that go are deleted. Nights that are added are priced by `RateQuoteService` for the added
     * stretch only (so a long-stay discount does not reach the old nights), and they try to keep the room or bed of
     * the neighbouring night when it is free (`unassignedNights` counts those that could not). Each nightly add-on
     * gets rows for the new nights (`AddNightlyAddOnRows`).
     *
     * A shorter departure runs `ProcessEarlyDeparture`: the removal of the unused nights, and the early departure policy
     * fee (`$doNotCharge`, `$reason` and `$expectedFee` work as in `CancelUnit`) only for a CheckedIn unit. A Confirmed
     * unit that has not arrived changes dates with no fee and no policy checks. A change that also moves the arrival is
     * a new booking of the unit, not an early departure, so it has no fee either. New nights must pass `RestrictionCheck`: a restriction needs `$restrictionOverrideReason`,
     * which the route passes only to a user with `rates.override-restrictions`. A Package plan is refused
     * (`package_plan`) and so is a day use unit (`day_use_unit`), which has no nights to change. Permissions are checked by the route. See docs/18-front-desk/01-check-in-and-check-out.md.
     */
    public function __invoke(
        ?User $actor,
        ReservationUnit $unit,
        CarbonInterface $arrival,
        CarbonInterface $departure,
        bool $doNotCharge = false,
        ?string $reason = null,
        ?string $expectedFee = null,
        ?string $restrictionOverrideReason = null,
    ): StayDatesResult {
        return DB::transaction(function () use ($actor, $unit, $arrival, $departure, $doNotCharge, $reason, $expectedFee, $restrictionOverrideReason): StayDatesResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_INVALID_STATUS);
            }

            if ($locked->stay_type === StayType::DayUse) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_DAY_USE_UNIT);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $newArrival = CarbonImmutable::instance($arrival)->startOfDay();
            $newDeparture = CarbonImmutable::instance($departure)->startOfDay();
            $oldArrival = $locked->arrival_date->toImmutable();
            $oldDeparture = $locked->departure_date->toImmutable();

            if ($newDeparture->lte($newArrival)) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_INVALID_STAY);
            }

            if ($newArrival->equalTo($oldArrival) && $newDeparture->equalTo($oldDeparture)) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_DATES_UNCHANGED);
            }

            if ($locked->status === UnitStatus::CheckedIn) {
                if (! $newArrival->equalTo($oldArrival)) {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_ARRIVAL_LOCKED);
                }

                if ($newDeparture->lt($businessDate)) {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_DEPARTURE_IN_PAST);
                }
            } elseif (! $newArrival->equalTo($oldArrival) && $newArrival->lt($businessDate)) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_ARRIVAL_IN_PAST);
            }

            $ratePlan = $locked->ratePlan()->firstOrFail();

            if ($ratePlan->fixed_nights !== null) {
                return StayDatesResult::refused(UnitStayResult::REFUSAL_PACKAGE_PLAN);
            }

            $roomType = RoomType::query()->lockForUpdate()->findOrFail($locked->room_type_id);
            $addedNights = $this->addedNights($newArrival, $newDeparture, $oldArrival, $oldDeparture);

            if ($addedNights !== []) {
                $violations = $this->restrictionCheck->violations($ratePlan->id, $roomType->id, $newArrival, $newDeparture);
                $overrideReason = trim((string) $restrictionOverrideReason);

                if ($violations !== [] && $overrideReason === '') {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_RESTRICTED);
                }

                $soldOut = array_find(
                    $this->availability->nights($roomType, $newArrival, $newDeparture),
                    fn (NightAvailability $night): bool => $night->available < 1 && $this->isAdded($night->date, $addedNights),
                );

                if ($soldOut !== null) {
                    return StayDatesResult::refused(UnitStayResult::REFUSAL_NOT_AVAILABLE);
                }
            }

            $prices = [];

            foreach ($this->runs($addedNights) as $run) {
                $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
                    $ratePlan,
                    $roomType,
                    $run[0],
                    $run[array_key_last($run)]->addDay(),
                    $locked->adults,
                    array_values($locked->childAges()->pluck('age')->map(fn (mixed $age): int => (int) $age)->all()),
                    1,
                ));

                if ($quote->isRefused()) {
                    return StayDatesResult::refused((string) $quote->refusal);
                }

                foreach ($quote->nights as $price) {
                    $prices[$price->date->toDateString()] = $price;
                }
            }

            $currency = $locked->property->settings?->currency->getCurrencyCode() ?? 'MYR';
            $previousTotal = $this->total($locked, $currency);
            $fee = '0.00';
            $feeCharged = false;

            if ($newDeparture->lt($oldDeparture)) {
                $early = ($this->processEarlyDeparture)($actor, $locked, $newDeparture, $doNotCharge, $reason, $expectedFee, $locked->status === UnitStatus::CheckedIn, $newArrival);

                if ($early->isRefused()) {
                    return $early;
                }

                $fee = $early->fee;
                $feeCharged = $early->feeCharged;
            }

            $locked->nights()->whereDate('date', '<', $newArrival->toDateString())->delete();
            $locked->addOns()->whereNull('folio_line_id')->whereNotNull('service_date')->whereDate('service_date', '<', $newArrival->toDateString())->delete();
            $previousArrival = $locked->arrival_date->toDateString();
            $previousDeparture = $locked->departure_date->toDateString();
            $locked->forceFill(['arrival_date' => $newArrival, 'departure_date' => $newDeparture])->save();
            event(ReservationChanged::forUnit($locked, $previousArrival, $previousDeparture));

            $unassigned = $this->addNights($locked, $roomType, $addedNights, $prices);
            ($this->addNightlyAddOnRows)($locked, $addedNights, $businessDate->toImmutable());

            $newTotal = $this->total($locked, $currency);

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.unit_dates_changed')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'arrival_date' => $newArrival->toDateString(),
                    'departure_date' => $newDeparture->toDateString(),
                    'previous_arrival_date' => $oldArrival->toDateString(),
                    'previous_departure_date' => $oldDeparture->toDateString(),
                    'previous_total' => (string) $previousTotal->getAmount(),
                    'total' => (string) $newTotal->getAmount(),
                    'fee' => $fee,
                    'did_not_charge' => $doNotCharge,
                    'reason' => filled($reason) ? trim((string) $reason) : null,
                    'restriction_override_reason' => $addedNights !== [] && filled($restrictionOverrideReason) ? trim((string) $restrictionOverrideReason) : null,
                    'unassigned_nights' => $unassigned,
                    'business_date' => $businessDate->toDateString(),
                ])
                ->log('Unit dates changed');

            ($this->syncReservationStatus)($locked->reservation);

            StayDatesChanged::dispatch($locked->property_id, $locked->id);

            return StayDatesResult::done($locked, $fee, $feeCharged, $previousTotal, $newTotal, $unassigned);
        });
    }

    /**
     * The dates in the new stay that the unit does not hold now.
     *
     * @return list<CarbonImmutable>
     */
    private function addedNights(CarbonImmutable $newArrival, CarbonImmutable $newDeparture, CarbonImmutable $oldArrival, CarbonImmutable $oldDeparture): array
    {
        $added = [];

        for ($night = $newArrival; $night->lt($newDeparture); $night = $night->addDay()) {
            if ($night->lt($oldArrival) || $night->gte($oldDeparture)) {
                $added[] = $night;
            }
        }

        return $added;
    }

    /**
     * @param  list<CarbonImmutable>  $added
     */
    private function isAdded(CarbonInterface $date, array $added): bool
    {
        return array_any($added, fn (CarbonImmutable $night): bool => $night->toDateString() === $date->toDateString());
    }

    /**
     * Group dates into runs of consecutive nights.
     *
     * @param  list<CarbonImmutable>  $dates
     * @return list<non-empty-list<CarbonImmutable>>
     */
    private function runs(array $dates): array
    {
        $runs = [];

        foreach ($dates as $date) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][array_key_last($runs[$last])]->addDay()->equalTo($date)) {
                $runs[$last][] = $date;

                continue;
            }

            $runs[] = [$date];
        }

        return $runs;
    }

    /**
     * Create the added nights, and give each the room or bed of the nearest kept night when it is free.
     *
     * @param  list<CarbonImmutable>  $added
     * @param  array<string, NightPrice>  $prices
     * @return int the number of added nights left without a room or bed that the neighbour had
     */
    private function addNights(ReservationUnit $unit, RoomType $roomType, array $added, array $prices): int
    {
        $kept = $unit->nights()->orderBy('date')->get();
        $unassigned = 0;

        foreach ($this->runs($added) as $run) {
            $neighbour = $this->neighbour($kept, $run[0]);
            $carried = $neighbour === null ? null : ($roomType->sell_unit === SellUnit::Room ? $neighbour->room_id : $neighbour->bed_id);
            $free = $carried !== null
                && $this->freeUnits->firstTakenNight($roomType, $carried, $run[0], $run[array_key_last($run)]->addDay(), $unit->id, $unit->arrival_time, $unit->departure_time) === null;

            foreach ($run as $date) {
                $price = $prices[$date->toDateString()];

                $unit->nights()->create([
                    'date' => $date->toDateString(),
                    'rate_plan_id' => $unit->rate_plan_id,
                    'room_id' => $free && $roomType->sell_unit === SellUnit::Room ? $carried : null,
                    'bed_id' => $free && $roomType->sell_unit === SellUnit::Bed ? $carried : null,
                    'base_amount' => (string) $price->base->getAmount(),
                    'extra_amount' => (string) $price->extra->getAmount(),
                    'supplement_amount' => (string) $price->supplement->getAmount(),
                    'discount_amount' => (string) $price->discountAmount->getAmount(),
                    'amount' => (string) $price->net()->getAmount(),
                ]);
            }

            if ($carried !== null && ! $free) {
                $unassigned += count($run);
            }
        }

        return $unassigned;
    }

    /**
     * The kept night next to a run: the one before it, or the first one after it.
     *
     * @param  Collection<int, UnitNight>  $kept
     */
    private function neighbour($kept, CarbonImmutable $runStart): ?UnitNight
    {
        return $kept->last(fn (UnitNight $night): bool => $night->date->lt($runStart))
            ?? $kept->first(fn (UnitNight $night): bool => $night->date->gt($runStart));
    }

    private function total(ReservationUnit $unit, string $currency): Money
    {
        return $unit->nights()->get()->reduce(fn (Money $sum, UnitNight $night): Money => $sum->plus($night->amount), Money::zero($currency));
    }
}
