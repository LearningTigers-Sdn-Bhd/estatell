<?php

namespace App\Services\Reservations;

use App\Data\Reservations\UnitActionData;
use App\Enums\UnitAction;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;

/**
 * The actions the unit ⋯ menu lists: those the unit's status allows and the user's permissions cover. It reads only.
 */
class UnitActionMenu
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    /**
     * @return list<UnitActionData>
     */
    public function for(User $user, ReservationUnit $unit): array
    {
        $businessDate = $this->currentBusinessDate->date($unit->property()->firstOrFail());

        return collect(UnitAction::cases())
            ->filter(fn (UnitAction $action): bool => $action->isOpenFor($unit->status)
                && $user->can($action->permission()->value)
                && $this->isTimely($action, $unit, $businessDate?->toDateString()))
            ->map(fn (UnitAction $action): UnitActionData => UnitActionData::fromAction($action))
            ->values()
            ->all();
    }

    /**
     * The dates each Action accepts, so the menu never offers what it would refuse: check-in from the arrival date
     * until the departure date, check-out from the departure date, late check-out on the departure date, reinstate
     * and move room while the stay is still ahead. A checked-in unit past its departure can still be extended.
     */
    private function isTimely(UnitAction $action, ReservationUnit $unit, ?string $businessDate): bool
    {
        if ($businessDate === null) {
            return false;
        }

        $arrival = $unit->arrival_date->toDateString();
        $departure = $unit->departure_date->toDateString();

        return match ($action) {
            UnitAction::CheckIn => $businessDate >= $arrival && $businessDate < $departure,
            UnitAction::CheckOut => $businessDate >= $departure,
            UnitAction::LateCheckOut => $businessDate === $departure,
            UnitAction::Reinstate, UnitAction::MoveRoom => $businessDate < $departure,
            default => true,
        };
    }
}
