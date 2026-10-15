<?php

use App\Models\Property;
use App\Models\PropertySetting;

/**
 * Finishes the Basics step of an onboarding property: an address and a settings row. The factory already sets the
 * type, name, city and country.
 */
function completeOnboardingBasics(Property $property): void
{
    $property->update(['address_line1' => '12 Jalan Pantai']);

    PropertySetting::factory()->for($property)->create();
}
