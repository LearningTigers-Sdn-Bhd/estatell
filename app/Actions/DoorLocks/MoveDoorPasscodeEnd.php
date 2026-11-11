<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Models\DoorPasscode;
use App\Services\DoorLocks\TtlockApi;
use App\Services\DoorLocks\TtlockException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Move the end of a live Custom PIN to `$endsAt`, later or earlier, on the lock through its gateway, so a longer or shorter
 * stay keeps the same PIN. Returns false and changes nothing when the PIN cannot be moved (not Custom, not Active, no gateway,
 * the same end, an end that is already past or before the start, or TTLock refuses). The caller then issues a new PIN for a
 * longer stay and leaves a shorter one as it is. A Generated PIN is worked out from its window and cannot be moved this way.
 */
class MoveDoorPasscodeEnd
{
    public function __construct(private TtlockApi $api, private FreshAccessToken $freshAccessToken) {}

    public function __invoke(DoorPasscode $passcode, CarbonImmutable $endsAt): bool
    {
        $lock = $passcode->doorLock;
        $connection = $lock->property->doorLockConnection;

        if ($passcode->kind !== PasscodeKind::Custom
            || $passcode->status !== PasscodeStatus::Active
            || $passcode->ttlock_passcode_id === null
            || ! $lock->has_gateway
            || $connection === null
            || $endsAt->equalTo($passcode->ends_at)
            || $endsAt->lte(now())
            || $endsAt->lte($passcode->starts_at)) {
            return false;
        }

        // One more try after a pause: the gateway talks to one lock at a time, and two date changes close together can time out.
        foreach ([1, 2] as $attempt) {
            try {
                $this->api->changePasscodePeriod(($this->freshAccessToken)($connection), $lock->ttlock_lock_id, $passcode->ttlock_passcode_id, $passcode->starts_at, $endsAt);

                break;
            } catch (TtlockException $exception) {
                if ($attempt === 2) {
                    Log::warning('Door PIN end not moved', ['door_passcode_id' => $passcode->id, 'message' => $exception->getMessage()]);

                    return false;
                }

                Sleep::for(3)->seconds();
            }
        }

        $passcode->forceFill(['ends_at' => $endsAt])->save();

        activity()
            ->performedOn($passcode)
            ->event('door-locks.passcode-end-moved')
            ->withProperties(['property_id' => $passcode->property_id, 'door_lock_id' => $passcode->door_lock_id, 'ends_at' => $endsAt->toIso8601String()])
            ->log('Door PIN end moved');

        return true;
    }
}
