<?php

namespace App\Actions\Facilities;

use App\Models\FacilityGuide;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveFacilityGuide
{
    /**
     * Add a guide to the property, or change one (`$guide`). It is never deleted, so the sessions that used it keep it;
     * turning it off hides it from the pickers. Logs `facility_guide.created` or `facility_guide.updated`.
     * See docs/33-facilities/06-guides-and-boats.md#settings.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?FacilityGuide $guide = null): FacilityGuide
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $guide): FacilityGuide {
            $guide ??= new FacilityGuide;
            $guide->fill($attributes);
            $guide->property_id = $property->id;
            $event = $guide->exists ? 'facility_guide.updated' : 'facility_guide.created';
            $guide->save();

            activity()
                ->performedOn($guide)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $guide->name])
                ->log($event === 'facility_guide.created' ? 'Guide added' : 'Guide updated');

            return $guide;
        });
    }
}
