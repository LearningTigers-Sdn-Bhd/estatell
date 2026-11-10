<?php

namespace App\Actions\Boats;

use App\Models\BoatSailing;

class SyncBoatSailingCrew
{
    /**
     * Set the crew of a sailing: one member in charge and any number of assistants. The caller has checked that everyone
     * is a crew member of the property. Run it inside the caller's transaction.
     *
     * @param  list<int>  $assistantIds
     */
    public function __invoke(BoatSailing $sailing, ?int $inChargeId, array $assistantIds): void
    {
        $rows = [];

        foreach ($assistantIds as $id) {
            $rows[$id] = ['in_charge' => false];
        }

        if ($inChargeId !== null) {
            $rows[$inChargeId] = ['in_charge' => true];
        }

        // Clear the old in charge first, so the one-in-charge index never sees two rows during the sync.
        $sailing->crew()->newPivotQuery()->where('in_charge', true)->update(['in_charge' => false]);
        $sailing->crew()->sync($rows);
    }
}
