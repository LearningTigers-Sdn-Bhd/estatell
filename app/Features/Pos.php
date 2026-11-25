<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks Estatell's Point of Sale: outlets, registers, shifts, menu, orders, KDS, QR ordering, inventory,
 * restaurant meal check-in, and the inbound POS API. Turning it off is refused while the property has an
 * unclosed register shift.
 */
#[Name('pos')]
class Pos
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
