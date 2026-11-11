<?php

namespace App\Actions\BookingEngine;

use App\Enums\NearbyPlaceStatus;
use App\Models\NearbyPlace;
use App\Models\User;
use App\Services\BookingEngine\MapsPlace;
use Illuminate\Support\Facades\DB;

class AddApprovedNearbyPlace
{
    public function __construct(private ApproveNearbyPlace $approveNearbyPlace) {}

    /**
     * Add a place to the registry as Approved, linked to no property. A Pending place already in the registry becomes
     * Approved; an Approved or Rejected one does not change. Returns the status the place had before, or null when it is new.
     */
    public function __invoke(User $admin, MapsPlace $mapsPlace): ?NearbyPlaceStatus
    {
        return DB::transaction(function () use ($admin, $mapsPlace): ?NearbyPlaceStatus {
            $existing = NearbyPlace::query()->where('fingerprint', $mapsPlace->fingerprint)->lockForUpdate()->first();

            if ($existing !== null) {
                if ($existing->status === NearbyPlaceStatus::Pending) {
                    ($this->approveNearbyPlace)($admin, $existing);
                }

                return $existing->status;
            }

            $place = NearbyPlace::query()->create([
                'name' => $mapsPlace->name,
                'latitude' => $mapsPlace->latitude,
                'longitude' => $mapsPlace->longitude,
                'google_maps_url' => $mapsPlace->url,
                'fingerprint' => $mapsPlace->fingerprint,
                'status' => NearbyPlaceStatus::Approved,
            ]);

            activity()
                ->performedOn($place)
                ->causedBy($admin)
                ->event('nearby-place.created')
                ->withProperties(['name' => $place->name])
                ->log('Nearby place added');

            return null;
        });
    }
}
