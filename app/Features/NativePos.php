<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks Estatell's built-in POS: registers, shifts, menu, orders, PIN terminals and room charges. Turning it off is
 * refused while the property has an unclosed register shift. See docs/34-native-pos/.
 */
#[Name('native-pos')]
class NativePos
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
