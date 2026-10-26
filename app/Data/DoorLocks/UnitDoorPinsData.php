<?php

namespace App\Data\DoorLocks;

use Spatie\LaravelData\Data;

/**
 * The door PINs of one stay unit, for the reservation page. `locksWithoutPin` names locks on the unit's rooms that have no live PIN yet.
 */
class UnitDoorPinsData extends Data
{
    /**
     * @param  list<DoorPasscodeData>  $passcodes
     * @param  list<string>  $locksWithoutPin
     */
    public function __construct(
        public array $passcodes,
        public array $locksWithoutPin,
    ) {}
}
