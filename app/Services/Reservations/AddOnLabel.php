<?php

namespace App\Services\Reservations;

use App\Models\UnitAddOn;

/**
 * The name of an add-on on the folio and the Add-ons tab: the charge item name, and for a child price row the age
 * group, for example "Breakfast · Child 4–11". Load `chargeItem` and `ageGroup` first.
 */
class AddOnLabel
{
    public static function for(UnitAddOn $addOn): string
    {
        $name = $addOn->chargeItem->name;

        if ($addOn->property_age_group_id === null || $addOn->ageGroup === null) {
            return $name;
        }

        return $name.' · '.$addOn->ageGroup->label.' '.$addOn->ageGroup->min_age.'–'.$addOn->ageGroup->max_age;
    }
}
