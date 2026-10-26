<?php

namespace App\Services\DoorLocks;

use App\Data\DoorLocks\DoorPasscodeData;
use App\Data\DoorLocks\UnitDoorPinsData;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\ReservationUnit;

/**
 * Reads the door PINs of a stay unit for the reservation page. Returns null when there is nothing to show: no PIN was ever
 * issued and none of the unit's rooms has a lock.
 */
class UnitDoorPins
{
    public function forUnit(ReservationUnit $unit): ?UnitDoorPinsData
    {
        $passcodes = DoorPasscode::query()
            ->where('reservation_unit_id', $unit->id)
            ->with('doorLock')
            ->orderBy('id')
            ->get();

        $locksWithoutPin = [];

        if ($unit->status === UnitStatus::CheckedIn) {
            $live = $passcodes
                ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active, PasscodeStatus::Failed])
                ->pluck('door_lock_id');

            $locksWithoutPin = array_values(DoorLock::query()
                ->where('property_id', $unit->property_id)
                ->whereIn('room_id', $unit->nights()->whereNotNull('room_id')->select('room_id'))
                ->whereNotIn('id', $live)
                ->pluck('name')
                ->all());
        }

        if ($passcodes->isEmpty() && $locksWithoutPin === []) {
            return null;
        }

        return new UnitDoorPinsData(
            passcodes: array_values($passcodes->map(DoorPasscodeData::fromModel(...))->all()),
            locksWithoutPin: $locksWithoutPin,
        );
    }
}
