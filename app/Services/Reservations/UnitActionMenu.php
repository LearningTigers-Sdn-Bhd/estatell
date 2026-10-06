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
     * Reinstate needs a stay that is still ahead. A checked-in unit past its departure can still be extended.
     */
    private function isTimely(UnitAction $action, ReservationUnit $unit, ?string $businessDate): bool
    {
        if ($businessDate === null) {
            return false;
        }

        return match ($action) {
            UnitAction::Reinstate => $businessDate < $unit->departure_date->toDateString(),
            default => true,
        };
    }
}
