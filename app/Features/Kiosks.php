<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks self check-in kiosks: the Kiosks settings page, kiosk pairing and the kiosk API for the property.
 */
#[Name('kiosks')]
class Kiosks
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
