<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class UpdateRolePermissions
{
    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    /**
     * Replace a role's permissions. Returns false for the Owner role, which always holds every permission.
     *
     * @param  list<PermissionEnum>  $permissions
     */
    public function __invoke(User $actor, Role $role, array $permissions): bool
    {
        if ($role->default_role === DefaultRoleEnum::Owner) {
            return false;
        }

        $names = array_values(array_unique(array_map(fn (PermissionEnum $permission): string => $permission->value, $permissions)));

        DB::transaction(function () use ($actor, $role, $names): void {
            $before = $role->permissions()->orderBy('name')->pluck('name')->all();

            $role->permissions()->sync(Permission::query()->whereIn('name', $names)->pluck('id')->all());

            sort($names);

            activity()
                ->performedOn($role)
                ->causedBy($actor)
                ->event('role.permissions-updated')
                ->withProperties(['property_id' => $role->property_id, 'old' => $before, 'new' => $names])
                ->log('Role permissions updated');
        });

        $this->permissionRegistrar->forgetCachedPermissions();

        return true;
    }
}
