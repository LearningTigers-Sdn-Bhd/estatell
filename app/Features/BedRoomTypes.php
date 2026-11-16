<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Lets the property create room types sold by bed, for dorms. While off, a room type that already sells beds keeps it.
 */
#[Name('bed-room-types')]
class BedRoomTypes
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
