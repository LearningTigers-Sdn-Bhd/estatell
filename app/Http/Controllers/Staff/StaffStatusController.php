<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\DeactivateStaff;
use App\Actions\Staff\ReactivateStaff;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffStatusController extends Controller
{
    public function deactivate(Request $request, Property $property, User $user, DeactivateStaff $deactivateStaff): RedirectResponse
    {
        Inertia::flash('toast', $deactivateStaff($request->user(), $user)
            ? ['type' => 'success', 'message' => __(':name was deactivated.', ['name' => $user->name])]
            : ['type' => 'error', 'message' => __(':name cannot be deactivated.', ['name' => $user->name])]);

        return back(fallback: route('settings.staff.index', $property));
    }

    public function reactivate(Request $request, Property $property, User $user, ReactivateStaff $reactivateStaff): RedirectResponse
    {
        Inertia::flash('toast', $reactivateStaff($request->user(), $user)
            ? ['type' => 'success', 'message' => __(':name was reactivated.', ['name' => $user->name])]
            : ['type' => 'error', 'message' => __(':name cannot be reactivated.', ['name' => $user->name])]);

        return back(fallback: route('settings.staff.deactivated', $property));
    }
}
