<?php

namespace App\Actions\Staff;

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SeedDefaultRoles
{
    public function __construct(private PermissionRegistrar $permissionRegistrar) {}

    /**
     * Create the default roles for a property with their default permissions.
     *
     * Roles that already exist keep their permissions, so running it again never undoes an owner's edits.
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            foreach (DefaultRoleEnum::cases() as $defaultRole) {
                $role = Role::query()->firstOrCreate(
                    ['property_id' => $property->id, 'default_role' => $defaultRole],
                    ['name' => $defaultRole->label()],
                );

                if (! $role->wasRecentlyCreated) {
                    continue;
                }

                $permissionNames = array_map(fn (PermissionEnum $permission): string => $permission->value, $defaultRole->permissions());

                $role->permissions()->attach(Permission::query()->whereIn('name', $permissionNames)->pluck('id')->all());
            }
        });

        $this->permissionRegistrar->forgetCachedPermissions();
    }
}
