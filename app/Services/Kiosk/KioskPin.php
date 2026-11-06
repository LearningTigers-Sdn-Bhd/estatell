<?php

namespace App\Services\Kiosk;

use App\Enums\PasscodeStatus;
use App\Features\DoorLocks;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\StayLocks;
use Laravel\Pennant\Feature;

/**
 * Reads the door PIN a checked-in stay got from TTLock (`SyncStayPasscodes`, queued by the check-in), as the kiosk's
 * three states. Every lock of the stay must have a live PIN for `ready`; one that failed makes it `failed`, and one
 * still waiting makes it `pending`. When a stay has several locks the first lock's code is shown. A stay with no lock,
 * or a property whose TTLock connection is off, can never get a PIN, so it is `failed` at once. Just after check-in,
 * before the queued job has made a row, it is `pending` for `WAIT_SECONDS`. It reads only.
 */
class KioskPin
{
    /** How long the kiosk waits for the check-in job to start before the PIN counts as failed. */
    public const int WAIT_SECONDS = 120;

    public function __construct(private StayLocks $stayLocks) {}

    public function for(ReservationUnit $stay): KioskPinStatus
    {
        $locks = $this->stayLocks->current($stay);

        if (! Feature::for($stay->property)->active(DoorLocks::class) || $locks->isEmpty() || $stay->property->doorLockConnection?->enabled !== true) {
            return KioskPinStatus::failed();
        }

        $states = $locks->map(fn (DoorLock $lock): array => $this->state($stay, $lock));

        if ($states->contains(fn (array $state): bool => $state['status'] === KioskPinStatus::FAILED)) {
            return KioskPinStatus::failed();
        }

        if ($states->contains(fn (array $state): bool => $state['status'] === KioskPinStatus::PENDING)) {
            return KioskPinStatus::pending();
        }

        /** @var DoorPasscode $first */
        $first = $states->first()['passcode'];

        return KioskPinStatus::ready((string) $first->code, $first->starts_at, $first->ends_at);
    }

    /**
     * @return array{status: string, passcode: DoorPasscode|null}
     */
    private function state(ReservationUnit $stay, DoorLock $lock): array
    {
        $passcode = DoorPasscode::query()
            ->where('reservation_unit_id', $stay->id)
            ->where('door_lock_id', $lock->id)
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active, PasscodeStatus::Failed])
            ->where('ends_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($passcode === null) {
            $waiting = $stay->checked_in_at !== null && $stay->checked_in_at->gt(now()->subSeconds(self::WAIT_SECONDS));

            return ['status' => $waiting ? KioskPinStatus::PENDING : KioskPinStatus::FAILED, 'passcode' => null];
        }

        return match ($passcode->status) {
            PasscodeStatus::Active => $passcode->code === null ? ['status' => KioskPinStatus::FAILED, 'passcode' => null] : ['status' => KioskPinStatus::READY, 'passcode' => $passcode],
            PasscodeStatus::Pending => ['status' => KioskPinStatus::PENDING, 'passcode' => null],
            default => ['status' => KioskPinStatus::FAILED, 'passcode' => null],
        };
    }
}
