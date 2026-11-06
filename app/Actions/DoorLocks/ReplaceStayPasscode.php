<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorPasscode;
use App\Models\User;
use App\Services\DoorLocks\PasscodeWindow;
use Illuminate\Support\Str;

/**
 * Staff give a checked-in stay a PIN they typed. The new PIN is issued first, so a refusal (no gateway, PIN already on the lock)
 * leaves the stay's current PIN as it was. Then every live PIN of the stay on that lock is revoked, so the guest never has two.
 * The same code as the current PIN is a no-op.
 */
class ReplaceStayPasscode
{
    public function __construct(
        private IssueDoorPasscode $issueDoorPasscode,
        private RevokeDoorPasscode $revokeDoorPasscode,
        private PasscodeWindow $passcodeWindow,
    ) {}

    public function __invoke(DoorPasscode $current, string $code, ?User $actor = null): DoorLockResult
    {
        $unit = $current->reservationUnit;

        if ($unit === null || $unit->status !== UnitStatus::CheckedIn || ! $current->status->canTransitionTo(PasscodeStatus::Revoked)) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_UNCHANGEABLE);
        }

        if ($current->code === $code) {
            return DoorLockResult::done($current);
        }

        $lock = $current->doorLock;
        [$startsAt, $endsAt] = $this->passcodeWindow->forUnit($unit, PasscodeKind::Custom, $unit->property->settings->time_zone ?? 'Asia/Kuala_Lumpur');

        $result = ($this->issueDoorPasscode)($lock, PasscodeKind::Custom, $current->label, $startsAt, $endsAt, "stay:{$unit->id}:{$lock->id}:custom:".Str::uuid(), $code, $unit, $actor);

        if ($result->isRefused()) {
            return $result;
        }

        $warning = null;

        DoorPasscode::query()
            ->where('reservation_unit_id', $unit->id)
            ->where('door_lock_id', $lock->id)
            ->whereKeyNot($result->passcode->id)
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active])
            ->get()
            ->each(function (DoorPasscode $old) use ($actor, &$warning): void {
                $revoked = ($this->revokeDoorPasscode)($old->load('doorLock.property.doorLockConnection'), $actor);
                $warning ??= $revoked->isRefused() ? $revoked->message : $revoked->warning;
            });

        return DoorLockResult::done($result->passcode, $warning);
    }
}
