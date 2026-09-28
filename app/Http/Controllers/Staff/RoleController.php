<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\CreateRole;
use App\Actions\Staff\UpdateRolePermissions;
use App\Data\Staff\PermissionOptionData;
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
    /**
     * The roles grid: permissions as rows, roles as columns.
     */
    public function index(Property $property): Response
    {
        return Inertia::render('settings/roles/index', [
            'roles' => $property->roles()
                ->withCount(['users' => fn ($query) => $query->whereNull('deactivated_at')])
                ->with('permissions')
                ->orderByRaw('default_role IS NULL')
                ->orderBy('id')
                ->get()
                ->map(fn (Role $role): RoleRowData => RoleRowData::fromModel($role)),
            'permissions' => array_map(fn (PermissionEnum $permission): PermissionOptionData => PermissionOptionData::fromEnum($permission), PermissionEnum::cases()),
        ]);
    }

    public function store(CreateRoleRequest $request, Property $property, CreateRole $createRole): RedirectResponse
    {
        $role = $createRole($property, $request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role :name created. Tick its permissions and save.', ['name' => $role->name])]);

        return to_route('settings.roles.index', $property);
    }

    public function update(UpdateRolePermissionsRequest $request, Property $property, UpdateRolePermissions $updateRolePermissions): RedirectResponse
    {
        $changed = $updateRolePermissions($request->user(), $property, $request->permissionsByRoleId());

        Inertia::flash('toast', $changed > 0
            ? ['type' => 'success', 'message' => trans_choice('Permissions saved for :count role.|Permissions saved for :count roles.', $changed)]
            : ['type' => 'info', 'message' => __('No permissions changed.')]);

        return to_route('settings.roles.index', $property);
    }
}
