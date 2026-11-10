<?php

namespace App\Actions\DoorLocks;

use App\Enums\LockProvider;
use App\Events\StayCheckedIn;
use App\Events\StayCheckedOut;
use App\Events\StayDatesChanged;
use App\Events\StayRoomChanged;
use App\Features\DoorLocks;
use App\Jobs\SyncStayPasscodesJob;
use App\Models\Property;

/**
 * Listener for the stay events: queue a PIN sync only when the property has the Door locks feature and an enabled connection.
 */
class QueueStayPasscodeSync
{
    public function __invoke(StayCheckedIn|StayCheckedOut|StayRoomChanged|StayDatesChanged $event): void
    {
        $property = Property::query()->with('doorLockConnection')->find($event->propertyId);

        if ($property === null || $property->doorLockConnection?->enabled !== true || ! DoorLocks::allows($property, LockProvider::TTLock)) {
            return;
        }

        SyncStayPasscodesJob::dispatch($event->reservationUnitId);
    }
}
