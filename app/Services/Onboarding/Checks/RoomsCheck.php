<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\SellUnit;
use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when every active room type sold by room has at least one active room. NotStarted when the property has no
 * active room, InProgress when some room types have rooms and some do not. Without a room type sold by room there is
 * nothing to fill, so the check is NotStarted.
 */
class RoomsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        $roomTypes = $property->roomTypes()->active()->where('sell_unit', SellUnit::Room);

        $total = (clone $roomTypes)->count();

        if ($total === 0) {
            return StepState::NotStarted;
        }

        $withRooms = (clone $roomTypes)->whereHas('rooms', fn ($query) => $query->active())->count();

        return match (true) {
            $withRooms === 0 => StepState::NotStarted,
            $withRooms < $total => StepState::InProgress,
            default => StepState::Done,
        };
    }
}
