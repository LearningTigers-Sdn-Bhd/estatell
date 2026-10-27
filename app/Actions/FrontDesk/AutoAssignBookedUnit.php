<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\AssignmentResult;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\PropertySetting;
use App\Models\ReservationUnit;
use App\Models\User;

class AutoAssignBookedUnit
{
    public function __construct(private AutoAssignRooms $autoAssignRooms) {}

    /**
     * Give a just booked or changed unit a room or bed when the property has `auto_assign_rooms` on. Only a Confirmed
     * overnight unit with a night that has no room or bed is assigned, for the whole stay, so a staff pick is kept.
     * Nothing free is not a refusal: the unit stays as it is and check-in tries again. Returns null when skipped.
     * See docs/18-front-desk/02-room-and-bed-assignment.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit): ?AssignmentResult
    {
        $isOn = (bool) PropertySetting::query()->where('property_id', $unit->property_id)->value('auto_assign_rooms');

        if (! $isOn || $unit->status !== UnitStatus::Confirmed || $unit->stay_type !== StayType::Overnight) {
            return null;
        }

        $hasOpenNight = $unit->nights()->whereNull('room_id')->whereNull('bed_id')->exists();

        return $hasOpenNight ? ($this->autoAssignRooms)($actor, $unit) : null;
    }
}
