<?php

namespace App\Services\Onboarding\Checks;

use App\Enums\SellUnit;
use App\Enums\StepState;
use App\Models\Property;

/**
 * Done when every active room type (sold by room or by bed) has at least one active room and every active room of a
 * bed-sold type has at least one active bed. NotStarted when no room type has an active room, InProgress otherwise.
 * Without an active room type there is nothing to fill, so the check is NotStarted.
 */
class RoomsCheck implements StepCheck
{
    public function state(Property $property): StepState
    {
        $roomTypes = $property->roomTypes()->active();

        $total = (clone $roomTypes)->count();

        if ($total === 0) {
            return StepState::NotStarted;
        }

        $withRooms = (clone $roomTypes)->whereHas('rooms', fn ($query) => $query->active())->count();

        $bedRoomsWithoutBeds = $property->rooms()->active()
            ->whereHas('roomType', fn ($query) => $query->active()->where('sell_unit', SellUnit::Bed))
            ->whereDoesntHave('beds', fn ($query) => $query->active())
            ->exists();

        return match (true) {
            $withRooms === 0 => StepState::NotStarted,
            $withRooms < $total || $bedRoomsWithoutBeds => StepState::InProgress,
            default => StepState::Done,
        };
    }
}
