<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateRole
{
    /**
     * Create a custom role with no permissions. The owner picks permissions on the role page next.
     */
    public function __invoke(Property $property, User $actor, string $name): Role
    {
        return DB::transaction(function () use ($property, $actor, $name): Role {
            $role = Role::query()->create(['property_id' => $property->id, 'name' => $name]);

            activity()
                ->performedOn($role)
                ->causedBy($actor)
                ->event('role.created')
                ->withProperties(['property_id' => $property->id, 'name' => $name])
                ->log('Role created');

            return $role;
        });
    }
}
