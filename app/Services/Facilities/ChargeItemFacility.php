<?php

namespace App\Services\Facilities;

use App\Features\Facilities;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\Property;
use Laravel\Pennant\Feature;

/**
 * Finds the facility that owns a charge item, so the charge item page can send staff to the facility page instead.
 * See docs/33-facilities/02-facility-settings.md.
 */
class ChargeItemFacility
{
    /**
     * The id of the facility priced by this charge item, or null when none owns it or the Facilities feature is off.
     */
    public function facilityIdFor(Property $property, ChargeItem $chargeItem): ?int
    {
        if (! Feature::for($property)->active(Facilities::class)) {
            return null;
        }

        $id = Facility::query()->where('charge_item_id', $chargeItem->id)->value('id');

        return $id === null ? null : (int) $id;
    }
}
