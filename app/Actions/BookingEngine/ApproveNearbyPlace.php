<?php

namespace App\Actions\BookingEngine;

use App\Enums\NearbyPlaceStatus;
use App\Models\NearbyPlace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveNearbyPlace
{
    /**
     * Approve a place so it is suggested to nearby properties. Returns false when its status cannot change to Approved.
     */
    public function __invoke(User $admin, NearbyPlace $place): bool
    {
        return DB::transaction(function () use ($admin, $place): bool {
            $place = NearbyPlace::query()->lockForUpdate()->findOrFail($place->id);

            if (! $place->status->canTransitionTo(NearbyPlaceStatus::Approved)) {
                return false;
            }

            $place->update(['status' => NearbyPlaceStatus::Approved]);

            activity()
                ->performedOn($place)
                ->causedBy($admin)
                ->event('nearby-place.approved')
                ->log('Nearby place approved');

            return true;
        });
    }
}
