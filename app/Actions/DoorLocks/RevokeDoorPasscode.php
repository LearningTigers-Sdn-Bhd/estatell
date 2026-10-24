<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Models\DoorLockConnection;
use App\Models\DoorPasscode;
use App\Models\User;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;

/**
 * Take a PIN away. With a gateway the PIN is deleted from the lock. Without one it cannot be reached: the row is marked
 * Revoked and the result carries a warning that the lock still accepts the PIN until it ends. A PIN that never reached
 * the lock is just marked Revoked. A Revoked or Expired PIN is refused. A TTLock failure keeps the row as it was.
 */
class RevokeDoorPasscode
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(DoorPasscode $passcode, ?User $actor = null): DoorLockResult
    {
        if (! $passcode->status->canTransitionTo(PasscodeStatus::Revoked)) {
            return DoorLockResult::refused(DoorLockResult::REFUSAL_UNCHANGEABLE);
        }

        $lock = $passcode->doorLock;
        $warning = null;

        if ($passcode->ttlock_passcode_id !== null) {
            $connection = $lock->property->doorLockConnection;

            if ($lock->has_gateway && $connection !== null) {
                try {
                    $this->api->deletePasscode(($this->freshAccessToken)($connection), $lock->ttlock_lock_id, $passcode->ttlock_passcode_id);
                } catch (TtlockException $exception) {
                    $passcode->forceFill(['error' => $exception->getMessage()])->save();

                    return DoorLockResult::refused(DoorLockResult::REFUSAL_TTLOCK, $exception->getMessage(), $passcode);
                }
            } elseif ($passcode->ends_at->isFuture()) {
                $warning = $this->warningWithoutGateway($passcode, $connection);
            }
        }

        $passcode->forceFill(['status' => PasscodeStatus::Revoked, 'revoked_at' => now(), 'error' => null])->save();

        activity()
            ->performedOn($passcode)
            ->causedBy($actor)
            ->event('door-locks.passcode-revoked')
            ->withProperties(['property_id' => $passcode->property_id, 'door_lock_id' => $passcode->door_lock_id, 'warned' => $warning !== null])
            ->log('Door PIN revoked');

        return DoorLockResult::done($passcode, $warning);
    }

    /**
     * Without a gateway the PIN cannot be deleted from the lock. A Delete PIN typed on the keypad invalidates every PIN already
     * used on the lock, so staff are given one. If TTLock cannot make it, the warning says to use the TTLock app instead.
     */
    private function warningWithoutGateway(DoorPasscode $passcode, ?DoorLockConnection $connection): string
    {
        $lock = $passcode->doorLock;
        $until = $passcode->ends_at->toDateTimeString();

        try {
            $code = $connection === null ? null : $this->api->deleteCode(($this->freshAccessToken)($connection), $lock->ttlock_lock_id, now());
        } catch (TtlockException) {
            $code = null;
        }

        return $code === null
            ? __('The lock has no gateway, so it still accepts this PIN until :time. Delete it in the TTLock app to stop it sooner.', ['time' => $until])
            : __('The lock has no gateway, so it still accepts this PIN until :time. To stop it now, type :code on the lock keypad: that removes every PIN already used on this lock.', ['time' => $until, 'code' => $code]);
    }
}
