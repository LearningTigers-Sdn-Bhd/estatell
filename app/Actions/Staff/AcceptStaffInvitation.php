<?php

namespace App\Actions\Staff;

use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptStaffInvitation
{
    /**
     * Create the staff user from an open invitation, give them the invited role and close the invitation.
     *
     * Accepting proves the email, so it is marked verified. Returns false when the invitation is closed or expired,
     * or when the email got an account after the invitation was sent.
     *
     * @param  array{name: string, password: string}  $input
     */
    public function __invoke(StaffInvitation $invitation, array $input): User|false
    {
        return DB::transaction(function () use ($invitation, $input): User|false {
            $invitation = StaffInvitation::query()->with('role')->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
                return false;
            }

            if (User::query()->whereRaw('lower(email) = ?', [$invitation->email])->exists()) {
                return false;
            }

            $user = new User(['name' => $input['name'], 'email' => $invitation->email, 'password' => $input['password']]);
            $user->forceFill(['property_id' => $invitation->property_id, 'email_verified_at' => now()])->save();

            $previousTeamId = getPermissionsTeamId();
            setPermissionsTeamId($invitation->property_id);

            try {
                $user->assignRole($invitation->role);
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            $invitation->update(['accepted_at' => now()]);

            activity()
                ->performedOn($invitation)
                ->causedBy($user)
                ->event('staff.invitation-accepted')
                ->withProperties(['property_id' => $invitation->property_id, 'email' => $invitation->email, 'role' => $invitation->role->name])
                ->log('Staff invitation accepted');

            return $user;
        });
    }
}
