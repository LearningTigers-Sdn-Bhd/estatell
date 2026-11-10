<?php

namespace App\Actions\DoorLocks;

use App\Enums\KeyportJobType;
use App\Enums\LockProvider;
use App\Events\StayCheckedIn;
use App\Features\DoorLocks;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\StayLocks;

/**
 * Listener for a check-in: when the room has an eLock lock and the property has a paired front-desk PC, ask it to make the
 * guest card. It never writes by itself: a person puts a card on the reader before the job expires. Safe to repeat, because
 * the job key holds the unit and the lock.
 */
class QueueStayKeyCards
{
    public function __construct(private StayLocks $stayLocks, private CreateKeyportJob $createKeyportJob) {}

    public function __invoke(StayCheckedIn $event): void
    {
        $property = Property::query()->find($event->propertyId);

        if ($property === null || ! DoorLocks::allows($property, LockProvider::ELock) || ! $property->keyportDevices()->whereNull('revoked_at')->exists()) {
            return;
        }

        $unit = ReservationUnit::query()->find($event->reservationUnitId);

        foreach ($unit === null ? [] : $this->stayLocks->currentElock($unit) as $lock) {
            ($this->createKeyportJob)(null, $unit, $lock, KeyportJobType::Issue, 'checkin:'.$unit->id.':'.$lock->id);
        }
    }
}
