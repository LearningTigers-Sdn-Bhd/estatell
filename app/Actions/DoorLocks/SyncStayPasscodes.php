<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeMode;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\PasscodeWindow;
use Illuminate\Support\Collection;

/**
 * Make a unit's PINs match its stay. A checked-in unit gets one PIN on the lock of each room it sleeps in; any PIN of the unit on
 * another lock, and every PIN of a unit that is no longer checked in, is revoked. Safe to repeat: an issued PIN is kept (idempotency key
 * `stay:{unit}:{lock}`). Does nothing without an enabled connection. A custom mode falls back to a generated PIN on a lock without a gateway.
 */
class SyncStayPasscodes
{
    public function __construct(
        private IssueDoorPasscode $issueDoorPasscode,
        private RevokeDoorPasscode $revokeDoorPasscode,
        private PasscodeWindow $passcodeWindow,
    ) {}

    public function __invoke(ReservationUnit $unit): void
    {
        $property = $unit->property;
        $connection = $property->doorLockConnection;

        if ($connection === null || ! $connection->enabled) {
            return;
        }

        $locks = $unit->status === UnitStatus::CheckedIn ? $this->locksOf($unit) : collect();

        $unit->loadMissing('reservation.booker');
        $label = $this->label($unit);
        $timeZone = $property->settings->time_zone ?? 'Asia/Kuala_Lumpur';

        foreach ($locks as $lock) {
            $kind = $connection->passcode_mode === PasscodeMode::Custom && $lock->has_gateway ? PasscodeKind::Custom : PasscodeKind::Generated;
            [$startsAt, $endsAt] = $this->passcodeWindow->forUnit($unit, $kind, $timeZone);

            ($this->issueDoorPasscode)($lock, $kind, $label, $startsAt, $endsAt, "stay:{$unit->id}:{$lock->id}", unit: $unit);
        }

        DoorPasscode::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active, PasscodeStatus::Failed])
            ->whereNotIn('door_lock_id', $locks->pluck('id')->all())
            ->get()
            ->each(fn (DoorPasscode $passcode) => ($this->revokeDoorPasscode)($passcode));
    }

    /**
     * The locks of the rooms the unit sleeps in, from its nights.
     *
     * @return Collection<int, DoorLock>
     */
    private function locksOf(ReservationUnit $unit)
    {
        $roomIds = $unit->nights()->whereNotNull('room_id')->pluck('room_id')->unique();

        return DoorLock::query()->where('property_id', $unit->property_id)->whereIn('room_id', $roomIds)->with('property.doorLockConnection')->get();
    }

    private function label(ReservationUnit $unit): string
    {
        return mb_substr(trim('Stay '.$unit->reservation_id.' '.($unit->reservation->booker->full_name)), 0, 60);
    }
}
