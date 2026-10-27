<?php

namespace App\Services\Reservations;

use App\Actions\FrontDesk\UnitStayResult;
use App\Data\Reservations\UnitActionData;
use App\Enums\PermissionEnum;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitAction;
use App\Models\BusinessDate;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\EarlyCheckOutRule;
use App\Services\FrontDesk\StayDay;
use App\Services\FrontDesk\UndoCheckInRule;
use App\Services\FrontDesk\UndoCheckOutRule;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;

/**
 * The actions the unit ⋯ menu lists: those the unit's status allows, the dates allow and the user's permissions cover. A day use unit never lists Late check-out or Change dates. It reads only.
 */
class UnitActionMenu
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private EarlyCheckOutRule $earlyCheckOutRule,
        private UndoCheckInRule $undoCheckInRule,
        private UndoCheckOutRule $undoCheckOutRule,
        private StayDay $stayDay,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * @return list<UnitActionData>
     */
    public function for(User $user, ReservationUnit $unit): array
    {
        $current = $this->currentBusinessDate->get($unit->property()->firstOrFail());

        return array_values(collect(UnitAction::cases())
            ->filter(fn (UnitAction $action): bool => $action->isOpenFor($unit->status)
                && $user->can($action->permission()->value)
                && $this->isTimely($action, $unit, $current, $user))
            ->map(fn (UnitAction $action): UnitActionData => $this->item($action, $unit, $current))
            ->values()
            ->all());
    }

    private function item(UnitAction $action, ReservationUnit $unit, ?BusinessDate $current): UnitActionData
    {
        $item = UnitActionData::fromAction($action);

        if ($current === null || $unit->stay_type === StayType::DayUse) {
            return $item;
        }

        $businessDate = $current->date->toDateString();

        if ($action === UnitAction::CheckOutAsDayUse && $this->propertyToday->date($unit->property)->toDateString() !== $businessDate) {
            $item->disabled = true;
            $item->note = __('The guest stayed past midnight, so this is a night.');
        }

        if ($action === UnitAction::CheckOut) {
            $refusal = $this->earlyCheckOutRule->refusal($unit, $businessDate);

            if ($refusal === UnitStayResult::REFUSAL_ARRIVED_TODAY) {
                $item->disabled = true;
                $item->note = __('Arrived today, so there is no night to remove.');
            } elseif ($refusal === null) {
                $item->label = __('Check out early…');
            }
        }

        return $item;
    }

    /**
     * The dates each Action accepts, so the menu never offers what it would refuse: check-in from the arrival date
     * until the departure date, check-out from the departure date (or before it, see `isEarlyCheckOut()`), late check-out on the departure date, reinstate
     * and move room while the stay is still ahead. A checked-in unit past its departure can still be extended.
     */
    private function isTimely(UnitAction $action, ReservationUnit $unit, ?BusinessDate $current, User $user): bool
    {
        if ($current === null) {
            return false;
        }

        $businessDate = $current->date->toDateString();

        if ($action === UnitAction::UndoCheckIn) {
            return $this->undoCheckInRule->isOffered($unit, $current);
        }

        if ($action === UnitAction::UndoCheckOut) {
            return $this->undoCheckOutRule->isOffered($unit, $current);
        }

        $arrival = $unit->arrival_date->toDateString();

        if ($unit->stay_type === StayType::DayUse) {
            return $this->isTimelyDayUse($action, $arrival, $businessDate);
        }

        $departure = $unit->departure_date->toDateString();
        $stayDay = $this->stayDay->forCheckOut($unit->property, CarbonImmutable::parse($businessDate))->toDateString();

        return match ($action) {
            UnitAction::CheckIn => $this->checkInDay($unit, $current) >= $arrival && $this->checkInDay($unit, $current) < $departure,
            UnitAction::CheckOut => $stayDay >= $departure || $this->isEarlyCheckOut($unit, $businessDate, $user)
                || $this->earlyCheckOutRule->refusal($unit, $businessDate) === UnitStayResult::REFUSAL_ARRIVED_TODAY,
            UnitAction::CheckOutAsDayUse => $arrival === $businessDate && $departure > $businessDate
                && $user->can(PermissionEnum::ReservationsManage->value)
                && ! $unit->roomType()->where('sell_unit', SellUnit::Bed)->exists(),
            UnitAction::LateCheckOut => $stayDay === $departure,
            UnitAction::Reinstate, UnitAction::MoveRoom => $businessDate < $departure,
            default => true,
        };
    }

    private function checkInDay(ReservationUnit $unit, BusinessDate $current): string
    {
        return $this->stayDay->forCheckIn($unit, CarbonImmutable::parse($current->date->toDateString()))->toDateString();
    }

    /**
     * The dates a day use unit accepts, on its one date: check-in on the date, check-out and no-show from it, reinstate
     * and move room until it. Late check-out and change dates do not apply (no departure time to extend, no nights).
     */
    private function isTimelyDayUse(UnitAction $action, string $date, string $businessDate): bool
    {
        return match ($action) {
            UnitAction::CheckIn => $businessDate === $date,
            UnitAction::CheckOut, UnitAction::NoShow => $businessDate >= $date,
            UnitAction::Reinstate, UnitAction::MoveRoom => $businessDate <= $date,
            UnitAction::Cancel => true,
            UnitAction::LateCheckOut, UnitAction::CheckOutAsDayUse, UnitAction::ChangeDates, UnitAction::UndoCheckIn, UnitAction::UndoCheckOut => false,
        };
    }

    /**
     * An early check-out follows `EarlyCheckOutRule` (a same-day arrival has no night to keep) and needs the right to
     * change the dates.
     */
    private function isEarlyCheckOut(ReservationUnit $unit, string $businessDate, User $user): bool
    {
        return $this->earlyCheckOutRule->isOffered($unit, $businessDate)
            && $user->can(PermissionEnum::ReservationsManage->value);
    }
}
