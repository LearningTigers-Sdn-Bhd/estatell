<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class UpdateRolePermissions
{
    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    /**
     * Save the roles page grid: replace each listed role's permissions in one transaction.
     *
     * The Owner role is skipped because it always holds every permission. Roles left out keep their permissions.
     * Only roles whose permissions changed are written and logged. Returns how many roles changed.
     *
     * @param  array<int, list<PermissionEnum>>  $permissionsByRoleId
     */
    public function __invoke(User $actor, Property $property, array $permissionsByRoleId): int
    {
        $changed = DB::transaction(function () use ($actor, $property, $permissionsByRoleId): int {
            $permissionIds = Permission::query()->pluck('id', 'name');
            $roles = Role::query()
                ->where('property_id', $property->id)
                ->whereKey(array_keys($permissionsByRoleId))
                ->with('permissions')
                ->lockForUpdate()
                ->get();

            $changed = 0;

            foreach ($roles as $role) {
                if ($role->default_role === DefaultRoleEnum::Owner) {
                    continue;
                }

                $before = $role->permissions->pluck('name')->sort()->values()->all();
                $after = collect($permissionsByRoleId[$role->id])->map(fn (PermissionEnum $permission): string => $permission->value)->unique()->sort()->values()->all();

                if ($before === $after) {
                    continue;
                }

                $role->permissions()->sync($permissionIds->only($after)->values()->all());
                $changed++;

                activity()
                    ->performedOn($role)
                    ->causedBy($actor)
                    ->event('role.permissions-updated')
                    ->withProperties(['property_id' => $property->id, 'old' => $before, 'new' => $after])
                    ->log('Role permissions updated');
            }

            return $changed;
        });

        if ($changed > 0) {
            $this->permissionRegistrar->forgetCachedPermissions();
        }

        return $changed;
    }
}
