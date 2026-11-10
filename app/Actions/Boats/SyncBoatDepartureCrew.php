<?php

namespace App\Actions\Boats;

use App\Models\BoatDeparture;

class SyncBoatDepartureCrew
{
    /**
     * Set the default crew of a departure: one member in charge and any number of assistants. The caller has checked
     * that everyone is a crew member of the property. Run it inside the caller's transaction.
     *
     * @param  list<int>  $assistantIds
     */
    public function __invoke(BoatDeparture $boatDeparture, ?int $inChargeId, array $assistantIds): void
    {
        $rows = [];

        foreach ($assistantIds as $id) {
            $rows[$id] = ['in_charge' => false];
        }

        // The in charge row goes last so a person listed twice is kept as the one in charge.
        if ($inChargeId !== null) {
            $rows[$inChargeId] = ['in_charge' => true];
        }

        // Clear the old in charge first, so the one-in-charge index never sees two rows during the sync.
        $boatDeparture->crew()->newPivotQuery()->where('in_charge', true)->update(['in_charge' => false]);
        $boatDeparture->crew()->sync($rows);
    }
}
