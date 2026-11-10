<?php

namespace App\Services\Boats;

use App\Actions\Boats\SailingResult;
use App\Models\Boat;
use App\Models\BoatCrewMember;

/**
 * Checks the boat and crew picked for a sailing. A boat or crew member must be of the property and available, unless
 * the sailing already has them, so an old sailing can be saved after its boat was turned off. Reads only.
 */
class SailingChoices
{
    /**
     * The refusal code, or null when the choice is allowed.
     *
     * @param  list<int>  $assistantIds
     * @param  list<int>  $currentCrewIds  The crew already on the sailing.
     */
    public function refusal(int $propertyId, ?int $boatId, ?int $inChargeId, array $assistantIds, ?int $currentBoatId = null, array $currentCrewIds = []): ?string
    {
        if ($assistantIds !== [] && $inChargeId === null) {
            return SailingResult::REFUSAL_IN_CHARGE_REQUIRED;
        }

        if ($boatId !== null && $boatId !== $currentBoatId
            && ! Boat::query()->where('property_id', $propertyId)->where('active', true)->whereKey($boatId)->exists()) {
            return SailingResult::REFUSAL_BOAT_UNAVAILABLE;
        }

        $crewIds = array_values(array_unique(array_filter([$inChargeId, ...$assistantIds])));
        $newCrewIds = array_values(array_diff($crewIds, $currentCrewIds));

        if ($newCrewIds !== [] && BoatCrewMember::query()->where('property_id', $propertyId)->where('active', true)->whereKey($newCrewIds)->count() !== count($newCrewIds)) {
            return SailingResult::REFUSAL_BOAT_UNAVAILABLE;
        }

        return null;
    }
}
