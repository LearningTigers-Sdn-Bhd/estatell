<?php

namespace App\Data\DoorLocks;

use Spatie\LaravelData\Data;

/**
 * The door PINs of one stay unit, for the reservation page. `locksWithoutPin` lists locks on the unit's rooms that have no live PIN yet, with the reason when known. `sendNewPin` is true when a longer stay replaced a PIN, so staff must give the guest the newest one.
 */
class UnitDoorPinsData extends Data
{
    /**
     * @param  list<DoorPasscodeData>  $passcodes
     * @param  list<LockWithoutPinData>  $locksWithoutPin
     */
    public function __construct(
        public array $passcodes,
        public array $locksWithoutPin,
        public bool $sendNewPin,
    ) {}
}
