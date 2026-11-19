<?php

namespace App\Jobs;

use App\Actions\DoorLocks\RetryStayPasscodes;
use App\Actions\DoorLocks\SyncStayCards;
use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncStayPasscodesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * An expected TTLock failure is saved on the PIN row, so only an unexpected error is retried. A job released because
     * another sync of the same unit is running counts as an attempt, so the cap is on exceptions.
     */
    public int $tries = 10;

    public int $maxExceptions = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * Waits for the stay's transaction, so the job never reads a unit that is rolled back.
     */
    /**
     * Seconds to wait before asking TTLock again, after a PIN failed in a way that asking again could fix (the gateway did not answer). A retry
     * is a new job, not a release, so it does not use up this job's attempts. The waits add up to about 17 minutes, inside the 20 minutes the
     * welcome waits for the PIN (`SendStayWelcomeJob::DEADLINE_SECONDS`).
     *
     * @var list<int>
     */
    public const array RETRY_DELAYS = [30, 120, 300, 600];

    /**
     * `$retry` counts the retries before this one; 0 is the sync a check-in or a change asked for.
     */
    public function __construct(public int $reservationUnitId, public int $retry = 0)
    {
        $this->afterCommit();
    }

    /**
     * Until-processing uniqueness lets an event queue a second job while the first runs. This keeps the two syncs of one unit
     * from running together, so a check-out revoke cannot land between a check-in's PIN row and its TTLock call.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->reservationUnitId))->releaseAfter(10)->expireAfter(120)];
    }

    /**
     * A retry has its own id, so the retry waiting for its turn does not hold the lock of the unit's normal syncs: a check-out, a room move, the
     * guest's "Try again" or staff's "Reissue" in that time are still queued. It also means a unit has at most one retry waiting.
     */
    public function uniqueId(): string
    {
        return $this->retry === 0 ? (string) $this->reservationUnitId : $this->reservationUnitId.':retry';
    }

    /**
     * Make the unit's PINs match its stay with `SyncStayPasscodes`. An error here never changes the stay.
     */
    public function handle(SyncStayPasscodes $syncStayPasscodes, SyncStayCards $syncStayCards): void
    {
        $unit = ReservationUnit::query()->find($this->reservationUnitId);

        if ($unit === null) {
            return;
        }

        try {
            $again = $syncStayPasscodes($unit);
            $syncStayCards($unit);
        } finally {
            RetryStayPasscodes::finished($unit->id);
        }

        if ($again && isset(self::RETRY_DELAYS[$this->retry])) {
            $wait = self::RETRY_DELAYS[$this->retry];
            self::dispatch($unit->id, $this->retry + 1)->delay(now()->addSeconds($wait));
            // The pages show "preparing" while the retry waits, as they do for a retry the guest asked for, not "failed".
            RetryStayPasscodes::expectRetry($unit->id, $wait);
        }

        // A PIN that is ready, or no PIN to wait for, is what the welcome of a checked-in stay waits on; the welcome decides.
        if ($unit->status === UnitStatus::CheckedIn) {
            SendStayWelcomeJob::dispatch($unit->property_id, $unit->id);
        }
    }
}
