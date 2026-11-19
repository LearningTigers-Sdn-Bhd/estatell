<?php

namespace App\Jobs;

use App\Actions\FrontDesk\SendStayCheckOutEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the thank-you email of a stay that has just been checked out. Waits for the check-out's transaction, so it never reads a stay
 * that is rolled back, and is unique per stay, so a check-out that is retried sends one email.
 */
class SendStayCheckOutEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->reservationUnitId;
    }

    public function handle(SendStayCheckOutEmail $send): void
    {
        $send($this->propertyId, $this->reservationUnitId);
    }
}
