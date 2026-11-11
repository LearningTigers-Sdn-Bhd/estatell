<?php

namespace App\Actions\Boats;

use App\Models\Boat;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveBoat
{
    /**
     * Add a boat to the property, or change one (`$boat`): its name, registration, seats and whether it can be picked. A boat
     * is never deleted, so the runs that used it keep their name. Logs `boat.created` or `boat.updated`.
     *
     * @param  array{name: string, registration: ?string, seats: ?int, active: bool}  $attributes
     */
    public function __invoke(User $actor, Property $property, array $attributes, ?Boat $boat = null): Boat
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $boat): Boat {
            $boat ??= new Boat;
            $boat->fill($attributes);
            $boat->property_id = $property->id;
            $event = $boat->exists ? 'boat.updated' : 'boat.created';
            $boat->save();

            activity()
                ->performedOn($boat)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $property->id, 'name' => $boat->name])
                ->log($event === 'boat.created' ? 'Boat added' : 'Boat updated');

            return $boat;
        });
    }
}
