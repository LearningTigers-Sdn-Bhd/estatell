<?php

namespace App\Actions\BookingEngine;

use App\Models\NearbyPlace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RenameNearbyPlace
{
    /**
     * Give a place a clearer name on every property that has it. The fingerprint stays, so pasting the same Google Maps
     * link again still finds this place. An unchanged name writes nothing.
     */
    public function __invoke(User $admin, NearbyPlace $place, string $name): void
    {
        DB::transaction(function () use ($admin, $place, $name): void {
            $place = NearbyPlace::query()->lockForUpdate()->findOrFail($place->id);
            $oldName = $place->name;

            if ($oldName === $name) {
                return;
            }

            $place->update(['name' => $name]);

            activity()
                ->performedOn($place)
                ->causedBy($admin)
                ->event('nearby-place.renamed')
                ->withProperties(['old_name' => $oldName, 'name' => $name])
                ->log('Nearby place renamed');
        });
    }
}
