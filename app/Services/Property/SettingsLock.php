<?php

namespace App\Services\Property;

use App\Models\Property;

/**
 * Settings that stay fixed once a property has gone live, because money and business dates depend on them.
 */
class SettingsLock
{
    public function locksCurrencyAndTimeZone(Property $property): bool
    {
        return $property->went_live_at !== null;
    }
}
