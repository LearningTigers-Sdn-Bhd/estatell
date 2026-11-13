<?php

namespace App\Services\Reservations;

use App\Models\BoatTripAddOn;
use App\Models\UnitAddOn;

/**
 * The name of an add-on on the folio and the Add-ons tab: the charge item name, for a child price row the age group,
 * and for a boat fare the direction of its trip, for example "Breakfast · Child 4–11" or "Speedboat Fare · Boat out".
 * The direction has no date: a posted line keeps its description, and a trip's date moves with the stay. Load
 * `chargeItem`, `ageGroup` and `boatTripLinks.unitBoatTrip` first. See docs/24-boats/02-boat-trips.md#fare.
 */
class AddOnLabel
{
    public static function for(UnitAddOn $addOn): string
    {
        $parts = [$addOn->chargeItem->name];

        if ($addOn->property_age_group_id !== null && $addOn->ageGroup !== null) {
            $parts[] = $addOn->ageGroup->label.' '.$addOn->ageGroup->min_age.'–'.$addOn->ageGroup->max_age;
        }

        $trip = $addOn->boatTripLinks->first(fn (BoatTripAddOn $link): bool => $link->unitBoatTrip !== null)?->unitBoatTrip;

        if ($trip !== null) {
            $parts[] = $trip->direction->label();
        }

        return implode(' · ', $parts);
    }
}
