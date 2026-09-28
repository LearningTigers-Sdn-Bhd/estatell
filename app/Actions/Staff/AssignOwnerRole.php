<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;

class AssignOwnerRole
{
    /**
     * Give a user the Owner role of their property. The property's default roles must already be seeded.
     *
     * Runs outside `/p` routes too (registration, migrations), so it sets the permission team itself and restores it.
     */
    public function __invoke(Property $property, User $user): void
    {
        $ownerRole = Role::query()
            ->where('property_id', $property->id)
            ->where('default_role', DefaultRoleEnum::Owner)
            ->firstOrFail();

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($property->id);

        try {
            $user->assignRole($ownerRole);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
