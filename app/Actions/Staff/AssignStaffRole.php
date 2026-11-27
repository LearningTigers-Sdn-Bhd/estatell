<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\PropertyMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\Staff\PropertyOwners;
use Illuminate\Support\Facades\DB;

class AssignStaffRole
{
    public function __construct(private PropertyOwners $owners) {}

    /**
     * Give a staff user one role at the property, replacing their old role there. Their roles at other properties of
     * the group do not change.
     *
     * The request has already checked that the actor may give this role. Returns false for yourself, for someone who
     * is not a member here, for an Owner when the actor is not an Owner here, or when it would leave the property
     * without an active Owner.
     */
    public function __invoke(User $actor, Property $property, User $staff, Role $role): bool
    {
        if ($actor->is($staff) || $role->property_id !== $property->id) {
            return false;
        }

        return DB::transaction(function () use ($actor, $property, $staff, $role): bool {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            if (! PropertyMembership::query()->where('property_id', $property->id)->where('user_id', $staff->id)->exists()) {
                return false;
            }

            $staffIsOwner = $this->owners->isOwner($property, $staff);

            if ($staffIsOwner && $role->default_role !== DefaultRoleEnum::Owner && (! $this->owners->isOwner($property, $actor) || ! $this->owners->hasActiveOwnerOtherThan($property, $staff))) {
                return false;
            }

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($property->id);

            try {
                $staff->unsetRelation('roles');
                $before = $staff->roles()->value('name');
                $staff->syncRoles($role);
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.role-assigned')
                ->withProperties(['property_id' => $property->id, 'old' => $before, 'new' => $role->name])
                ->log('Staff role assigned');

            return true;
        });
    }
}
