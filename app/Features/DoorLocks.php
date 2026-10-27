<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the door lock integration (TTLock PINs issued for each stay).
 */
#[Name('door-locks')]
class DoorLocks
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
