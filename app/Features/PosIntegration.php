<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks outlets, restaurant meal check-in, POS room-charge postings and the inbound POS API.
 */
#[Name('pos-integration')]
class PosIntegration
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
