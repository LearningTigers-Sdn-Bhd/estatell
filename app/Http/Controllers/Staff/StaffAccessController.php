<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\GrantPropertyAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\GrantPropertyAccessRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StaffAccessController extends Controller
{
    /**
     * Settings › Staff › Add from another property: give a staff member of the group access here with a role.
     * See docs/03-staff/04-property-groups.md.
     */
    public function store(GrantPropertyAccessRequest $request, Property $property, GrantPropertyAccess $grantPropertyAccess): RedirectResponse
    {
        $staff = User::query()->findOrFail($request->integer('user_id'));
        $role = Role::query()->where('property_id', $property->id)->findOrFail($request->integer('role_id'));

        Inertia::flash('toast', $grantPropertyAccess($request->user(), $property, $staff, $role)
            ? ['type' => 'success', 'message' => __(':name can now open :property.', ['name' => $staff->name, 'property' => $property->name])]
            : ['type' => 'error', 'message' => __(':name cannot be given access here.', ['name' => $staff->name])]);

        return to_route('settings.staff.index', $property);
    }
}
