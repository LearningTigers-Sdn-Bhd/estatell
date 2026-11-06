<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeMode;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorPasscode;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\PasscodeWindow;
use App\Services\DoorLocks\StayLocks;

/**
 * Make a unit's PINs match its stay. A checked-in unit gets one PIN on the lock of each room it sleeps in; any PIN of the unit on
 * another lock, and every PIN of a unit that is no longer checked in, is revoked. Safe to repeat: an issued PIN is kept (idempotency key
 * `stay:{unit}:{lock}`). When the departure moves later, a PIN that ends at the new departure is issued under `stay:{unit}:{lock}:{end}` and the old one is left to run out. Does nothing without an enabled connection. A custom mode falls back to a generated PIN on a lock without a gateway.
 */
class SyncStayPasscodes
{
    public function __construct(
        private IssueDoorPasscode $issueDoorPasscode,
        private RevokeDoorPasscode $revokeDoorPasscode,
        private MoveDoorPasscodeEnd $moveDoorPasscodeEnd,
        private PasscodeWindow $passcodeWindow,
        private StayLocks $stayLocks,
    ) {}

    public function __invoke(ReservationUnit $unit): void
    {
        $property = $unit->property;
        $connection = $property->doorLockConnection;

        if ($connection === null || ! $connection->enabled) {
            return;
        }

        $locks = $unit->status === UnitStatus::CheckedIn ? $this->stayLocks->for($unit) : collect();

        $unit->loadMissing('reservation.booker');
        $label = $this->label($unit);
        $timeZone = $property->settings->time_zone ?? 'Asia/Kuala_Lumpur';

        foreach ($locks as $lock) {
            $kind = $connection->passcode_mode === PasscodeMode::Custom && $lock->has_gateway ? PasscodeKind::Custom : PasscodeKind::Generated;
            [$startsAt, $endsAt] = $this->passcodeWindow->forUnit($unit, $kind, $timeZone);

            $key = "stay:{$unit->id}:{$lock->id}";
            $live = DoorPasscode::query()->where('reservation_unit_id', $unit->id)->where('door_lock_id', $lock->id)->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active]);

            // A Custom PIN is moved to the new end through the gateway, later or earlier, so the guest keeps the same PIN.
            $current = $live->clone()->where('kind', PasscodeKind::Custom)->latest('id')->first();

            if ($current !== null && ($this->moveDoorPasscodeEnd)($current->load('doorLock.property.doorLockConnection'), $endsAt)) {
                continue;
            }

            // A PIN that already lasts to the end of the stay stays as it is. A Generated PIN cannot be shortened; check-out revokes it.
            if ($live->clone()->where('ends_at', '>=', $endsAt)->exists()) {
                continue;
            }

            // Otherwise a longer stay needs a new PIN: a generated PIN cannot be edited without a gateway. The old one is left to run out.
            if ($live->clone()->where('idempotency_key', $key)->exists()) {
                $key .= ':'.$endsAt->getTimestamp();
            }

            ($this->issueDoorPasscode)($lock, $kind, $label, $startsAt, $endsAt, $key, unit: $unit, length: $connection->passcode_length);
        }

        DoorPasscode::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active, PasscodeStatus::Failed])
            ->whereNotIn('door_lock_id', $locks->pluck('id')->all())
            ->get()
            ->each(fn (DoorPasscode $passcode) => ($this->revokeDoorPasscode)($passcode));
    }

    private function label(ReservationUnit $unit): string
    {
        return mb_substr(trim('Stay '.$unit->reservation_id.' '.($unit->reservation->booker->full_name)), 0, 60);
    }
}
