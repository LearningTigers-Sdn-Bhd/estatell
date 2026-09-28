<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\AssignStaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\AssignStaffRoleRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StaffRoleController extends Controller
{
    public function update(AssignStaffRoleRequest $request, Property $property, User $user, AssignStaffRole $assignStaffRole): RedirectResponse
    {
        $role = Role::query()->findOrFail($request->validated('role_id'));

        Inertia::flash('toast', $assignStaffRole($request->user(), $user, $role)
            ? ['type' => 'success', 'message' => __(':name is now :role.', ['name' => $user->name, 'role' => $role->name])]
            : ['type' => 'error', 'message' => __('The role of :name cannot be changed.', ['name' => $user->name])]);

        return back(fallback: route('settings.staff.index', $property));
    }
}
