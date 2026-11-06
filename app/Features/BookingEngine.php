<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the Booking engine module (booking website settings, public API, online payment) for a property.
 */
#[Name('booking-engine')]
class BookingEngine
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
