<?php

namespace App\Services\Reservations;

use App\Data\Reservations\UnitActionData;
use App\Enums\PermissionEnum;
use App\Enums\StayType;
use App\Enums\UnitAction;
use App\Models\BusinessDate;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\FrontDesk\EarlyCheckOutRule;
use App\Services\FrontDesk\UndoCheckInRule;
use App\Services\FrontDesk\UndoCheckOutRule;
use App\Services\NightAudit\CurrentBusinessDate;

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
    ) {}

    /**
     * @return list<UnitActionData>
     */
    public function for(User $user, ReservationUnit $unit): array
    {
        $current = $this->currentBusinessDate->get($unit->property()->firstOrFail());

        return collect(UnitAction::cases())
            ->filter(fn (UnitAction $action): bool => $action->isOpenFor($unit->status)
                && $user->can($action->permission()->value)
                && $this->isTimely($action, $unit, $current, $user))
            ->map(fn (UnitAction $action): UnitActionData => UnitActionData::fromAction($action))
            ->values()
            ->all();
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

        return match ($action) {
            UnitAction::CheckIn => $businessDate >= $arrival && $businessDate < $departure,
            UnitAction::CheckOut => $businessDate >= $departure || $this->isEarlyCheckOut($unit, $businessDate, $user),
            UnitAction::LateCheckOut => $businessDate === $departure,
            UnitAction::Reinstate, UnitAction::MoveRoom => $businessDate < $departure,
            default => true,
        };
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
            UnitAction::LateCheckOut, UnitAction::ChangeDates, UnitAction::UndoCheckIn, UnitAction::UndoCheckOut => false,
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
