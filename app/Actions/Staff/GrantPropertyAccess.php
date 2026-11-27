<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GrantPropertyAccess
{
    /**
     * Let a staff member of another property in the group open this property, with one role here. Their home property,
     * role and password do not change; they switch between properties from the navbar. See
     * docs/03-staff/04-property-groups.md.
     *
     * The request has already checked that the actor may give this role. Returns false when the property has no
     * group, the staff member's home property is not in it (or is this property), their home membership is not active,
     * they already have a membership here (a deactivated one is reactivated instead), or the role is not this
     * property's.
     */
    public function __invoke(User $actor, Property $property, User $staff, Role $role): bool
    {
        if ($role->property_id !== $property->id || $staff->property_id === null || $staff->property_id === $property->id) {
            return false;
        }

        return DB::transaction(function () use ($actor, $property, $staff, $role): bool {
            $locked = Property::query()->lockForUpdate()->findOrFail($property->id);
            $home = Property::query()->find($staff->property_id);

            if ($locked->property_group_id === null || $home?->property_group_id !== $locked->property_group_id) {
                return false;
            }

            $homeActive = PropertyMembership::query()->where('property_id', $home->id)->where('user_id', $staff->id)->whereNull('deactivated_at')->exists();
            $alreadyMember = PropertyMembership::query()->where('property_id', $locked->id)->where('user_id', $staff->id)->exists();

            if (! $homeActive || $alreadyMember) {
                return false;
            }

            $staff->memberships()->create(['property_id' => $locked->id]);
            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($locked->id);

            try {
                $staff->unsetRelation('roles');
                $staff->assignRole($role);
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.access-granted')
                ->withProperties(['property_id' => $locked->id, 'home_property_id' => $home->id, 'role' => $role->name])
                ->log('Staff given access from another property');

            return true;
        });
    }
}
