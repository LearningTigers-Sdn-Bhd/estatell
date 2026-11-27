<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the Land transfers module (road routes, vehicles, drivers and the run sheet) for properties that pick guests up by road.
 */
#[Name('transfers')]
class Transfers
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
