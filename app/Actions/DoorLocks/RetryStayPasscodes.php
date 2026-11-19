<?php

namespace App\Actions\DoorLocks;

use App\Enums\UnitStatus;
use App\Jobs\SyncStayPasscodesJob;
use App\Models\ReservationUnit;
use Illuminate\Support\Facades\Cache;

/**
 * Ask for a checked-in stay's door PINs again, for the guest's "Try again" and staff's "Reissue". It queues the same sync
 * the check-in does, which is safe to repeat: a Pending or Active PIN is kept and a Failed one is tried again, so
 * nobody ends up with two PINs. While it runs `underway()` is true, so the pages show "preparing" instead of "failed".
 * The job clears it when it ends; it also lapses by itself, in case no queue worker ever picks the job up.
 * False, queuing nothing, for a stay that is not checked in.
 */
class RetryStayPasscodes
{
    private const int SECONDS = 90;

    public function __invoke(ReservationUnit $unit): bool
    {
        if ($unit->status !== UnitStatus::CheckedIn) {
            return false;
        }

        Cache::put(self::key($unit->id), true, self::SECONDS);
        SyncStayPasscodesJob::dispatch($unit->id);

        return true;
    }

    /**
     * A retry of the sync itself is waiting for its turn (`SyncStayPasscodesJob::RETRY_DELAYS`): the pages show "preparing" until it ran, with
     * the same room to run as a retry that was asked for.
     */
    public static function expectRetry(int $unitId, int $waitSeconds): void
    {
        Cache::put(self::key($unitId), true, $waitSeconds + self::SECONDS);
    }

    public static function underway(int $unitId): bool
    {
        return Cache::has(self::key($unitId));
    }

    public static function finished(int $unitId): void
    {
        Cache::forget(self::key($unitId));
    }

    private static function key(int $unitId): string
    {
        return "pin-retry:{$unitId}";
    }
}
