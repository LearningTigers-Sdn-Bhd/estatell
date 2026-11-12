<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the LHDN MyInvois e-invoicing feature for a property.
 */
#[Name('e-invoicing')]
class EInvoicing
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
