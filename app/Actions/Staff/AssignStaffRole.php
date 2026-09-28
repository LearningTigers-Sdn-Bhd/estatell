<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignStaffRole
{
    /**
     * Give a staff user one role in their property, replacing the old one.
     *
     * The request has already checked that the actor may give this role. Returns false for yourself,
     * for an Owner when the actor is not an Owner, or when it would leave the property without an active Owner.
     */
    public function __invoke(User $actor, User $staff, Role $role): bool
    {
        if ($actor->is($staff) || $role->property_id !== $staff->property_id) {
            return false;
        }

        return DB::transaction(function () use ($actor, $staff, $role): bool {
            Property::query()->lockForUpdate()->findOrFail($staff->property_id);

            $ownerRole = Role::query()->where('property_id', $staff->property_id)->where('default_role', DefaultRoleEnum::Owner)->firstOrFail();
            $staffIsOwner = $ownerRole->users()->whereKey($staff->id)->exists();

            if ($staffIsOwner && $role->isNot($ownerRole)) {
                $actorIsOwner = $ownerRole->users()->whereKey($actor->id)->exists();
                $otherActiveOwner = $ownerRole->users()->whereKeyNot($staff->id)->whereNull('deactivated_at')->exists();

                if (! $actorIsOwner || ! $otherActiveOwner) {
                    return false;
                }
            }

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($staff->property_id);

            try {
                $before = $staff->roles()->pluck('name')->first();
                $staff->syncRoles($role);
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            activity()
                ->performedOn($staff)
                ->causedBy($actor)
                ->event('staff.role-assigned')
                ->withProperties(['property_id' => $staff->property_id, 'old' => $before, 'new' => $role->name])
                ->log('Staff role assigned');

            return true;
        });
    }
}
