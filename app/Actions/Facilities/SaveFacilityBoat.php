<?php

namespace App\Actions\Facilities;

use App\Models\FacilityBoat;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveFacilityBoat
{
    /**
     * Add a boat to the property, or change one (`$boat`). It is never deleted, so the sessions that used it keep it;
     * turning it off hides it from the pickers. Logs `facility_boat.created` or `facility_boat.updated`.
     * See docs/33-facilities/06-guides-and-boats.md#settings.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?FacilityBoat $boat = null): FacilityBoat
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $boat): FacilityBoat {
            $boat ??= new FacilityBoat;
            $boat->fill($attributes);
            $boat->property_id = $property->id;
            $event = $boat->exists ? 'facility_boat.updated' : 'facility_boat.created';
            $boat->save();

            activity()
                ->performedOn($boat)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $boat->name])
                ->log($event === 'facility_boat.created' ? 'Boat added' : 'Boat updated');

            return $boat;
        });
    }
}
