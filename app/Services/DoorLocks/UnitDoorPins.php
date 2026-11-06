<?php

namespace App\Services\DoorLocks;

use App\Data\DoorLocks\DoorPasscodeData;
use App\Data\DoorLocks\LockWithoutPinData;
use App\Data\DoorLocks\UnitDoorPinsData;
use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorPasscode;
use App\Models\ReservationUnit;
use Illuminate\Support\Collection;

/**
 * Reads the door PINs of a stay unit for the reservation page. Returns null when there is nothing to show: no PIN was ever
 * issued and none of the unit's rooms has a lock.
 */
class UnitDoorPins
{
    public function __construct(private PasscodeWindow $passcodeWindow, private StayLocks $stayLocks) {}

    public function forUnit(ReservationUnit $unit): ?UnitDoorPinsData
    {
        $passcodes = DoorPasscode::query()
            ->where('reservation_unit_id', $unit->id)
            ->with('doorLock')
            ->orderByDesc('id')
            ->get();

        $locksWithoutPin = [];
        $current = $unit->status === UnitStatus::CheckedIn ? $this->stayLocks->current($unit) : null;

        if ($unit->status === UnitStatus::CheckedIn) {
            $live = $passcodes
                ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active, PasscodeStatus::Failed])
                ->pluck('door_lock_id');

            $reason = $this->reasonWithoutPin($unit);

            $locksWithoutPin = array_values($current
                ->whereNotIn('id', $live->all())
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

        $live = $passcodes->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active]);
        $leavingIds = array_values($live->whereNotIn('door_lock_id', $current?->modelKeys() ?? [])->map(fn (DoorPasscode $passcode): int => $passcode->id)->all());
        $making = collect($locksWithoutPin)->contains(fn (LockWithoutPinData $lock): bool => $lock->reason === null);
        $updating = $unit->status === UnitStatus::CheckedIn && $this->endsBeforeStay($unit, $passcodes);

        return new UnitDoorPinsData(
            passcodes: array_values($passcodes->map(DoorPasscodeData::fromModel(...))->all()),
            locksWithoutPin: $locksWithoutPin,
            sendNewPin: $sendNewPin,
            updating: $updating,
            making: $making,
            leavingIds: $leavingIds,
            syncing: $making || $updating || $leavingIds !== [] || $live->contains('status', PasscodeStatus::Pending),
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

    /**
     * True when a lock of the unit has live PINs whose end is not the end of the stay yet (a Custom PIN) or does not reach it (a Generated PIN): the sync after a date change has not caught up.
     *
     * @param  Collection<int, DoorPasscode>  $passcodes
     */
    private function endsBeforeStay(ReservationUnit $unit, $passcodes): bool
    {
        $timeZone = $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur';

        return $passcodes
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active])
            ->groupBy('door_lock_id')
            ->contains(function ($live) use ($unit, $timeZone): bool {
                [, $endsAt] = $this->passcodeWindow->forUnit($unit, $live->first()->kind, $timeZone);

                $latest = $live->max('ends_at');

                // A Custom PIN is moved to the stay's end both ways. A Generated PIN is only ever replaced by a later one.
                return $live->first()->kind === PasscodeKind::Custom
                    ? $latest->diffInSeconds($endsAt, true) > 60
                    : $latest->lt($endsAt);
            });
    }
}
