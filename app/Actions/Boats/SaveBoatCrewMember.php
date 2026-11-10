<?php

namespace App\Actions\Boats;

use App\Models\BoatCrewMember;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveBoatCrewMember
{
    /**
     * Add a crew member to the property, or change one (`$crewMember`): the name, phone and whether they can be picked.
     * A crew member is never deleted, so old trips keep the name. Logs `boat_crew_member.created` or
     * `boat_crew_member.updated`. See docs/24-boats/01-boat-settings.md#crew-tab.
     *
     * @param  array{name: string, phone: ?string, active: bool}  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?BoatCrewMember $crewMember = null): BoatCrewMember
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $crewMember): BoatCrewMember {
            $crewMember ??= new BoatCrewMember;
            $crewMember->fill($attributes);
            $crewMember->property_id = $property->id;
            $event = $crewMember->exists ? 'boat_crew_member.updated' : 'boat_crew_member.created';
            $crewMember->save();

            activity()
                ->performedOn($crewMember)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $crewMember->name])
                ->log($event === 'boat_crew_member.created' ? 'Boat crew member added' : 'Boat crew member updated');

            return $crewMember;
        });
    }
}
