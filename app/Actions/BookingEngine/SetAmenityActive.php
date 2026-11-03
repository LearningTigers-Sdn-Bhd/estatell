<?php

namespace App\Actions\BookingEngine;

use App\Models\Amenity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetAmenityActive
{
    /**
     * Retire an amenity or bring it back. A retired amenity leaves the pickers and stays where it is already ticked.
     * Returns false when the amenity already has that state.
     */
    public function __invoke(User $admin, Amenity $amenity, bool $active): bool
    {
        return DB::transaction(function () use ($admin, $amenity, $active): bool {
            $amenity = Amenity::query()->lockForUpdate()->findOrFail($amenity->id);

            if ($amenity->active === $active) {
                return false;
            }

            $amenity->update(['active' => $active]);

            activity()
                ->performedOn($amenity)
                ->causedBy($admin)
                ->event($active ? 'amenity.activated' : 'amenity.retired')
                ->log($active ? 'Amenity turned on' : 'Amenity retired');

            return true;
        });
    }
}
