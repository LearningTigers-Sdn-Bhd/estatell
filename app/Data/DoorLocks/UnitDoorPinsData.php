<?php

namespace App\Data\DoorLocks;

use Spatie\LaravelData\Data;

/**
 * The door PINs of one stay unit, for the reservation page. `locksWithoutPin` lists locks on the unit's rooms that have no live PIN yet, with the reason when known. `syncing` is true while the PINs are not yet what the stay needs, so the page keeps reloading them: a PIN is being made (`making`), a live PIN is on a lock the stay no longer uses after a check-out, undo or room move (`leavingIds`), a PIN is Pending, or a live PIN's end is off (`updating`). `updating` is true while a checked-in unit's live PIN ends before the stay does, because the job that follows a date change has not run yet. `sendNewPin` is true when a longer stay replaced a PIN, so staff must give the guest the newest one.
 */
class UnitDoorPinsData extends Data
{
    /**
     * @param  list<DoorPasscodeData>  $passcodes
     * @param  list<LockWithoutPinData>  $locksWithoutPin
     * @param  list<int>  $leavingIds
     */
    public function __construct(
        public array $passcodes,
        public array $locksWithoutPin,
        public bool $sendNewPin,
        public bool $updating,
        public bool $making,
        public array $leavingIds,
        public bool $syncing,
    ) {}
}
