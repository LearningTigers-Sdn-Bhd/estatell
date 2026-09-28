<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\CreateRole;
use App\Actions\Staff\UpdateRolePermissions;
use App\Data\Staff\PermissionOptionData;
use App\Data\Staff\RoleData;
use App\Data\Staff\RoleRowData;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CreateRoleRequest;
use App\Http\Requests\Staff\UpdateRolePermissionsRequest;
use App\Models\Property;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/roles/index', [
            'roles' => $property->roles()
                ->withCount(['users' => fn ($query) => $query->whereNull('deactivated_at'), 'permissions'])
                ->orderByRaw('default_role IS NULL')
                ->orderBy('id')
                ->get()
                ->map(fn (Role $role): RoleRowData => RoleRowData::fromModel($role)),
        ]);
    }

    public function store(CreateRoleRequest $request, Property $property, CreateRole $createRole): RedirectResponse
    {
        $role = $createRole($property, $request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name created. Choose its permissions.', ['name' => $role->name])]);

        return to_route('settings.roles.edit', [$property, $role]);
    }

    public function edit(Property $property, Role $role): Response
    {
        return Inertia::render('settings/roles/edit', [
            'role' => RoleData::fromModel($role),
            'permissions' => array_map(fn (PermissionEnum $permission): PermissionOptionData => PermissionOptionData::fromEnum($permission), PermissionEnum::cases()),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, Property $property, Role $role, UpdateRolePermissions $updateRolePermissions): RedirectResponse
    {
        Inertia::flash('toast', $updateRolePermissions($request->user(), $role, $request->permissions())
            ? ['type' => 'success', 'message' => __('Permissions for :name saved.', ['name' => $role->name])]
            : ['type' => 'error', 'message' => __('The Owner role always has every permission.')]);

        return back(fallback: route('settings.roles.edit', [$property, $role]));
    }
}
