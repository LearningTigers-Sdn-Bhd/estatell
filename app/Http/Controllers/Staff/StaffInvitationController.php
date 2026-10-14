<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResendStaffInvitation;
use App\Actions\Staff\RevokeStaffInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\InviteStaffRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\StaffInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffInvitationController extends Controller
{
    public function store(InviteStaffRequest $request, Property $property, InviteStaff $inviteStaff): RedirectResponse
    {
        $invitation = $inviteStaff(
            $property,
            $request->user(),
            $request->validated('email'),
            Role::query()->findOrFail($request->integer('role_id')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $invitation->email])]);

        return to_route('settings.staff.invited', $property);
    }

    public function resend(Request $request, Property $property, StaffInvitation $staffInvitation, ResendStaffInvitation $resendStaffInvitation): RedirectResponse
    {
        Inertia::flash('toast', $resendStaffInvitation($request->user(), $staffInvitation)
            ? ['type' => 'success', 'message' => __('Invitation resent to :email.', ['email' => $staffInvitation->email])]
            : ['type' => 'error', 'message' => __('This invitation is no longer open.')]);

        return back(fallback: route('settings.staff.invited', $property));
    }

    public function destroy(Request $request, Property $property, StaffInvitation $staffInvitation, RevokeStaffInvitation $revokeStaffInvitation): RedirectResponse
    {
        Inertia::flash('toast', $revokeStaffInvitation($request->user(), $staffInvitation)
            ? ['type' => 'success', 'message' => __('Invitation to :email revoked.', ['email' => $staffInvitation->email])]
            : ['type' => 'error', 'message' => __('This invitation is no longer open.')]);

        return back(fallback: route('settings.staff.invited', $property));
    }
}
