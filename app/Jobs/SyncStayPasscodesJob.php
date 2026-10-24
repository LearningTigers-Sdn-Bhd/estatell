<?php

namespace App\Jobs;

use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Models\ReservationUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStayPasscodesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * An expected TTLock failure is saved on the PIN row, so only an unexpected error is retried.
     */
    public int $tries = 3;

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
