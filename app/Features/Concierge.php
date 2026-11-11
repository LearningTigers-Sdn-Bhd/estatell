<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the Concierge page (guest self check-in on the guest's own phone, and later the stay pages) for a property.
 */
#[Name('concierge')]
class Concierge
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
