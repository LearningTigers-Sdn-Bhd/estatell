<?php

namespace App\Actions\Platform;

use App\Models\Property;
use App\Models\PropertyGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddPropertyToGroup
{
    /**
     * Put a property in the group, so staff of the group can be given access to it and its staff to the others.
     * Nobody gets access by this alone. Returns false when the property is already in a group.
     * See docs/03-staff/04-property-groups.md.
     */
    public function __invoke(User $admin, PropertyGroup $group, Property $property): bool
    {
        return DB::transaction(function () use ($admin, $group, $property): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->property_group_id !== null) {
                return false;
            }

            $property->forceFill(['property_group_id' => $group->id])->save();

            activity()
                ->performedOn($group)
                ->causedBy($admin)
                ->event('property-group.property-added')
                ->withProperties(['property_id' => $property->id])
                ->log('Property added to group');

            return true;
        });
    }
}
