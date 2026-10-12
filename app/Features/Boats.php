<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the Boats module (departures, fares and boat trips on bookings) for island and jetty properties.
 */
#[Name('boats')]
class Boats
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
