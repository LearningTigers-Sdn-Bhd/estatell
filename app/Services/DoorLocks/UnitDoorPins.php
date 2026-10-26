<?php

namespace App\Services\DoorLocks;

use App\Data\DoorLocks\DoorPasscodeData;
use App\Data\DoorLocks\LockWithoutPinData;
use App\Data\DoorLocks\UnitDoorPinsData;
use App\Enums\PasscodeKind;
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
    public function __construct(private PasscodeWindow $passcodeWindow) {}

    public function forUnit(ReservationUnit $unit): ?UnitDoorPinsData
    {
        $passcodes = DoorPasscode::query()
            ->where('reservation_unit_id', $unit->id)
            ->with('doorLock')
            ->orderByDesc('id')
            ->get();

        $locksWithoutPin = [];

        if ($unit->status === UnitStatus::CheckedIn) {
            $live = $passcodes
                ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active, PasscodeStatus::Failed])
                ->pluck('door_lock_id');

            $reason = $this->reasonWithoutPin($unit);

            $locksWithoutPin = array_values(DoorLock::query()
                ->where('property_id', $unit->property_id)
                ->whereIn('room_id', $unit->nights()->whereNotNull('room_id')->select('room_id'))
                ->whereNotIn('id', $live)
                ->pluck('name')
                ->map(fn (string $name): LockWithoutPinData => new LockWithoutPinData($name, $reason))
                ->all());
        }

        if ($passcodes->isEmpty() && $locksWithoutPin === []) {
            return null;
        }

        $sendNewPin = $passcodes
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active])
            ->filter(fn (DoorPasscode $passcode): bool => $passcode->ends_at->isFuture())
            ->countBy('door_lock_id')
            ->contains(fn (int $count): bool => $count > 1);

        return new UnitDoorPinsData(
            passcodes: array_values($passcodes->map(DoorPasscodeData::fromModel(...))->all()),
            locksWithoutPin: $locksWithoutPin,
            sendNewPin: $sendNewPin,
        );
    }

    /**
     * Why a checked-in unit has no PIN, when we can tell from the stay and the connection.
     */
    private function reasonWithoutPin(ReservationUnit $unit): ?string
    {
        if ($unit->property->doorLockConnection?->enabled !== true) {
            return __('Issue PINs automatically is off.');
        }

        $timeZone = $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';
        [, $endsAt] = $this->passcodeWindow->forUnit($unit, PasscodeKind::Generated, $timeZone);

        return $endsAt->lte(now()) ? __('The stay has already ended, so a PIN would be out of date.') : null;
    }
}
