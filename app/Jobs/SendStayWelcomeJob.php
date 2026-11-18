<?php

namespace App\Jobs;

use App\Actions\Concierge\SendConciergeStayLink;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskPin;
use App\Services\Kiosk\KioskPinStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the welcome of a checked-in stay (email and `stay.welcome_ready`) once its door PIN is ready, so the guest gets one
 * message with the PIN in it. While TTLock is still making the PIN the job asks again every few seconds. A PIN that failed, a
 * stay with no lock, or a PIN still missing after `HOLD_SECONDS` sends the welcome without the PIN: the stay page has it.
 */
class SendStayWelcomeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How long the welcome waits for the PIN: as long as the stay page waits before it calls a PIN failed. */
    public const int HOLD_SECONDS = KioskPin::WAIT_SECONDS;

    private const int POLL_SECONDS = 5;

    /** Room for the polls of the hold: a released job counts as an attempt. */
    public int $tries = 40;

    public int $maxExceptions = 3;

    public int $uniqueFor = 300;

    /** When the hold ends, as a Unix time. */
    public readonly int $holdUntil;

    /**
     * Waits for the check-in's transaction, so the job never reads a stay that is rolled back.
     */
    public function __construct(public readonly int $propertyId, public readonly int $reservationUnitId)
    {
        $this->holdUntil = now()->addSeconds(self::HOLD_SECONDS)->getTimestamp();
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->reservationUnitId;
    }

    public function handle(KioskPin $pins, SendConciergeStayLink $send): void
    {
        $stay = ReservationUnit::query()->where('property_id', $this->propertyId)->with(['property.settings', 'property.doorLockConnection'])->find($this->reservationUnitId);

        if ($stay === null) {
            return;
        }

        $pin = $pins->for($stay);

        if ($pin->status === KioskPinStatus::PENDING && now()->getTimestamp() < $this->holdUntil) {
            $this->release(self::POLL_SECONDS);

            return;
        }

        $send($this->propertyId, $this->reservationUnitId, $pin);
    }
}
