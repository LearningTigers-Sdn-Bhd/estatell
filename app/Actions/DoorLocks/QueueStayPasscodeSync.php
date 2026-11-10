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
 * Listener for the stay events: queue the stay sync when the property has the Door locks feature and a TTLock connection. PINs are only issued while the connection is enabled (the job checks), but cards follow the stay either way.
 */
class QueueStayPasscodeSync
{
    public function __invoke(StayCheckedIn|StayCheckedOut|StayRoomChanged|StayDatesChanged $event): void
    {
        $property = Property::query()->with('doorLockConnection')->find($event->propertyId);

        // The job decides what to do with a connection that does not issue PINs automatically: cards still follow the stay.
        if ($property === null || $property->doorLockConnection === null || ! DoorLocks::allows($property, LockProvider::TTLock)) {
            return;
        }

        SyncStayPasscodesJob::dispatch($event->reservationUnitId);
    }
}
