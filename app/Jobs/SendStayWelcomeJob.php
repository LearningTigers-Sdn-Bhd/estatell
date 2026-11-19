<?php

namespace App\Jobs;

use App\Actions\Concierge\WelcomeStay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asks `WelcomeStay` whether the welcome of a checked-in stay can go out now. It is queued by the check-in, by every end of a PIN sync
 * (`SyncStayPasscodesJob`), and once more as the deadline of the check-in (`$deadlineFor`, the check-in time as a Unix time), which sends the
 * welcome even if the door PIN could not be made. It sends nothing it should not: `WelcomeStay` waits for a PIN that is on its way and sends
 * once.
 */
class SendStayWelcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How long a stay waits for its door PIN, after the check-in, before the welcome goes without it. */
    public const int DEADLINE_SECONDS = 1200;

    public int $tries = 3;

    /**
     * Waits for the check-in's transaction, so the job never reads a stay that is rolled back.
     */
    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId, public readonly ?int $deadlineFor = null)
    {
        $this->afterCommit();
    }

    public function handle(WelcomeStay $welcome): void
    {
        $welcome($this->propertyId, $this->reservationUnitId, $this->deadlineFor);
    }
}
