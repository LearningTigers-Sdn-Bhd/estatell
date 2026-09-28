<?php

namespace App\Actions\Staff;

use App\Enums\PermissionEnum;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions
{
    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    /**
     * Create a permission row for every new PermissionEnum case and grant it to the default roles that include it.
     *
     * Only permissions created in this run are granted, so a permission an owner removed from a role stays removed.
     * Custom roles are never touched. Safe to run again: a second run changes nothing.
     */
    public function __invoke(): void
    {
        DB::transaction(function (): void {
            $existingNames = Permission::query()->pluck('name')->all();

            $newPermissionIds = [];

            foreach (PermissionEnum::cases() as $permission) {
                if (! in_array($permission->value, $existingNames, true)) {
                    $newPermissionIds[$permission->value] = Permission::query()->create(['name' => $permission->value])->id;
                }
            }

            if ($newPermissionIds === []) {
                return;
            }

            Role::query()->whereNotNull('default_role')->lazyById()->each(function (Role $role) use ($newPermissionIds): void {
                $defaultNames = array_map(fn (PermissionEnum $permission): string => $permission->value, $role->default_role->permissions());

                $role->permissions()->attach(array_values(array_intersect_key($newPermissionIds, array_flip($defaultNames))));
            });
        });

        $this->permissionRegistrar->forgetCachedPermissions();
    }
}
