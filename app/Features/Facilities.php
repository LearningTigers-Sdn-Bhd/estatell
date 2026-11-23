<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks Facilities (halls, equipment and activities staff book by date and time) and event folios.
 */
#[Name('facilities')]
class Facilities
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
