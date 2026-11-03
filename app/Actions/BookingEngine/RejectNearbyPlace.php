<?php

namespace App\Actions\BookingEngine;

use App\Enums\NearbyPlaceStatus;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\User;
use App\Notifications\NearbyPlaceRemoved;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;

class RejectNearbyPlace
{
    public function __construct(private PropertyOwners $owners) {}

    /**
     * Reject a place. It leaves every property that had it, and each property's owner gets an in-app notice. Returns
     * false when its status cannot change to Rejected.
     */
    public function __invoke(User $admin, NearbyPlace $place): bool
    {
        return DB::transaction(function () use ($admin, $place): bool {
            $place = NearbyPlace::query()->lockForUpdate()->findOrFail($place->id);

            if (! $place->status->canTransitionTo(NearbyPlaceStatus::Rejected)) {
                return false;
            }

            $properties = $place->properties()->get();
            $place->properties()->detach();
            $place->update(['status' => NearbyPlaceStatus::Rejected]);

            activity()
                ->performedOn($place)
                ->causedBy($admin)
                ->event('nearby-place.rejected')
                ->withProperties(['removed_from_properties' => $properties->count()])
                ->log('Nearby place rejected');

            $properties->each(function (Property $property) use ($place): void {
                $owner = $this->owners->firstFor($property);

                if ($owner !== null && $owner->deactivated_at === null) {
                    $owner->notify(new NearbyPlaceRemoved($place->name, $property->name));
                }
            });

            return true;
        });
    }
}
