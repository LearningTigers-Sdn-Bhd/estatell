<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the accounting integration (daily journals pushed to the property's accounting software).
 */
#[Name('accounting-integration')]
class AccountingIntegration
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
