<?php

namespace App\Jobs;

use App\Actions\DoorLocks\SyncStayPasscodes;
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
    public function __construct(public int $reservationUnitId)
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

    public function uniqueId(): string
    {
        return (string) $this->reservationUnitId;
    }

    /**
     * Make the unit's PINs match its stay with `SyncStayPasscodes`. An error here never changes the stay.
     */
    public function handle(SyncStayPasscodes $syncStayPasscodes): void
    {
        $unit = ReservationUnit::query()->find($this->reservationUnitId);

        if ($unit === null) {
            return;
        }

        $syncStayPasscodes($unit);
    }
}
