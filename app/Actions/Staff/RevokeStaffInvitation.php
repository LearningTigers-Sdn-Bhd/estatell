<?php

namespace App\Actions\Staff;

use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokeStaffInvitation
{
    /**
     * Stop the link from working and free the email for a new invitation. Returns false when the invitation is closed.
     */
    public function __invoke(User $revoker, StaffInvitation $invitation): bool
    {
        return DB::transaction(function () use ($revoker, $invitation): bool {
            $invitation = StaffInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                return false;
            }

            $invitation->update(['revoked_at' => now()]);

            activity()
                ->performedOn($invitation)
                ->causedBy($revoker)
                ->event('staff.invitation-revoked')
                ->withProperties(['property_id' => $invitation->property_id, 'email' => $invitation->email])
                ->log('Staff invitation revoked');

            return true;
        });
    }
}
