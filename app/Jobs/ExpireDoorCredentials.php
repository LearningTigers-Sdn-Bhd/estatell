<?php

namespace App\Jobs;

use App\Actions\DoorLocks\FreshAccessToken;
use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The lock enforces the end time itself, so a PIN or card past `ends_at` is already dead there. This brings our rows in line
 * (Active becomes Expired) and, on a lock with a gateway, deletes the credential from the lock so its memory does not fill up.
 */
class ExpireDoorCredentials implements ShouldQueue
{
    use Queueable;

    public function handle(TtlockApi $api, FreshAccessToken $freshAccessToken): void
    {
        DoorPasscode::query()->with('doorLock.property.doorLockConnection')->where('status', PasscodeStatus::Active)->where('ends_at', '<=', now())
            ->eachById(function (DoorPasscode $passcode) use ($api, $freshAccessToken): void {
                $this->deleteFromLock($passcode->doorLock, $passcode->ttlock_passcode_id, $freshAccessToken, fn (string $token) => $api->deletePasscode($token, $passcode->doorLock->ttlock_lock_id, $passcode->ttlock_passcode_id));
                $passcode->forceFill(['status' => PasscodeStatus::Expired])->save();
            });

        DoorCard::query()->with('doorLock.property.doorLockConnection')->where('status', PasscodeStatus::Active)->where('ends_at', '<=', now())
            ->eachById(function (DoorCard $card) use ($api, $freshAccessToken): void {
                $this->deleteFromLock($card->doorLock, $card->ttlock_card_id, $freshAccessToken, fn (string $token) => $api->deleteCard($token, $card->doorLock->ttlock_lock_id, $card->ttlock_card_id));
                $card->forceFill(['status' => PasscodeStatus::Expired])->save();
            });
    }

    /**
     * One try. A failure is dropped because the row is Expired either way and the lock refuses it already.
     * ponytail: no retry, so a lock that was offline keeps the dead PIN in its memory. Add a `removed_from_lock_at` column and a retry if that fills a lock.
     *
     * @param  Closure(string): mixed  $delete
     */
    private function deleteFromLock(DoorLock $lock, ?int $remoteId, FreshAccessToken $freshAccessToken, Closure $delete): void
    {
        $connection = $lock->property->doorLockConnection;

        if ($remoteId === null || ! $lock->has_gateway || $connection === null) {
            return;
        }

        try {
            $delete($freshAccessToken($connection));
        } catch (TtlockException) {
            // Kept on the lock until it is deleted by hand. See the ponytail note above.
        }
    }
}
