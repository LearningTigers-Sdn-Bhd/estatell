<?php

namespace App\Actions\Concierge;

use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Services\Kiosk\KioskPin;
use App\Services\Kiosk\KioskPinStatus;
use Illuminate\Support\Facades\DB;

/**
 * Decides when the welcome of a checked-in stay goes out (the email and `stay.welcome_ready`, by `SendConciergeStayLink`) and sends it once.
 * A stay with no door PIN to wait for (no lock, or TTLock off) is welcomed at once. A stay whose PIN is on its way waits: the welcome goes as
 * soon as the PIN is ready, with the PIN in it, or when the deadline of the check-in comes, without the PIN (the stay page has it when it
 * arrives, and the log says the welcome went without it). Several things ask, the check-in, each end of a PIN sync and the deadline, and the
 * first that can send wins: the welcome is claimed with an atomic update of `welcome_sent_at`, so it is sent once per check-in. A stay checked
 * in again later is welcomed again. See docs/30-concierge/01-overview.md#the-welcome.
 */
class WelcomeStay
{
    public function __construct(private KioskPin $pins, private SendConciergeStayLink $send) {}

    /**
     * `$deadlineFor` is the check-in time (a Unix time) of the deadline that asks, or null for any other asker. A deadline of an earlier
     * check-in, one that was undone, never cuts the wait of the stay's later check-in short.
     */
    public function __invoke(int $propertyId, int $reservationUnitId, ?int $deadlineFor = null): void
    {
        $stay = ReservationUnit::query()->where('property_id', $propertyId)->with(['property.settings', 'property.doorLockConnection'])->find($reservationUnitId);

        if ($stay === null || $stay->status !== UnitStatus::CheckedIn || $this->welcomed($stay)) {
            return;
        }

        if ($deadlineFor !== null && $stay->checked_in_at?->getTimestamp() !== $deadlineFor) {
            return;
        }

        $pin = $this->pins->for($stay);
        $ready = $pin->status === KioskPinStatus::READY;
        $expected = $this->pins->canRetry($stay);

        if (! $ready && $expected && $deadlineFor === null) {
            return;
        }

        DB::transaction(function () use ($stay, $pin, $ready, $expected): void {
            if (! $this->claim($stay)) {
                return;
            }

            ($this->send)($stay->property_id, $stay->id, $ready ? $pin : null);

            if (! $ready && $expected) {
                activity()
                    ->performedOn($stay)
                    ->event('door-locks.welcome-without-pin')
                    ->withProperties(['property_id' => $stay->property_id, 'reservation_unit_id' => $stay->id])
                    ->log('Welcome sent without the door PIN, which could not be made in time');
            }
        });
    }

    /**
     * Whether this check-in was welcomed already.
     */
    private function welcomed(ReservationUnit $stay): bool
    {
        return $stay->welcome_sent_at !== null && ($stay->checked_in_at === null || $stay->welcome_sent_at->gte($stay->checked_in_at));
    }

    /**
     * Takes the right to send, once: true for the one caller whose update changed the row.
     */
    private function claim(ReservationUnit $stay): bool
    {
        return ReservationUnit::query()
            ->whereKey($stay->id)
            ->where(fn ($unwelcomed) => $unwelcomed->whereNull('welcome_sent_at')->orWhereColumn('welcome_sent_at', '<', 'checked_in_at'))
            ->update(['welcome_sent_at' => now()]) === 1;
    }
}
